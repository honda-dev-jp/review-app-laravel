"""Claude Code PreToolUseポリシーHookの回帰テスト。"""

from __future__ import annotations

import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest import mock


HOOK_PATH = Path(__file__).resolve().parents[1] / "pre_tool_use.py"
SPEC = importlib.util.spec_from_file_location("pre_tool_use", HOOK_PATH)
assert SPEC is not None and SPEC.loader is not None
hook = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(hook)


def payload(tool_name: str, tool_input: dict[str, object], **extra: object) -> dict[str, object]:
    value: dict[str, object] = {
        "session_id": "session-test",
        "transcript_path": "/tmp/synthetic-transcript.jsonl",
        "cwd": "/tmp/synthetic-project",
        "permission_mode": "default",
        "hook_event_name": "PreToolUse",
        "tool_name": tool_name,
        "tool_input": tool_input,
        "tool_use_id": "tool-test",
    }
    value.update(extra)
    return value


def bash(command: str, environ: dict[str, str] | None = None, **fields: object) -> dict[str, object]:
    tool_input: dict[str, object] = {"command": command}
    tool_input.update(fields)
    return hook.evaluate_input(payload("Bash", tool_input), {} if environ is None else environ)


def webfetch(url: str, prompt: str = "Summarize this official page", **fields: object) -> dict[str, object]:
    tool_input: dict[str, object] = {"url": url, "prompt": prompt}
    tool_input.update(fields)
    return hook.evaluate_input(payload("WebFetch", tool_input), {})


def outcome(result: dict[str, object]) -> tuple[str, str]:
    specific = result["hookSpecificOutput"]
    assert isinstance(specific, dict)
    return str(specific["permissionDecision"]), str(specific["permissionDecisionReason"])


class InputValidationTests(unittest.TestCase):
    def test_malformed_json_returns_deny_and_exit_zero(self) -> None:
        # 初期版はprocess errorへ分岐せず、exit code 0の構造化Denyへ一本化する。
        # malformed入力でもClaude Codeへ判定JSONを返すprotocolを固定する。
        stdin = io.StringIO("{")
        stdout = io.StringIO()
        self.assertEqual(hook.run(stdin, stdout, {}), 0)
        result = json.loads(stdout.getvalue())
        self.assertEqual(outcome(result), ("deny", hook.REASONS["malformed"]))

    def test_top_level_must_be_object(self) -> None:
        self.assertEqual(outcome(hook.evaluate_input([], {}))[0], "deny")

    def test_required_fields_and_types_are_validated(self) -> None:
        missing = payload("Bash", {"command": "pwd"})
        del missing["session_id"]
        wrong_type = payload("Bash", {"command": "pwd"})
        wrong_type["cwd"] = 1
        for item in (missing, wrong_type):
            with self.subTest(item=item):
                self.assertEqual(outcome(hook.evaluate_input(item, {}))[0], "deny")

    def test_hook_event_name_must_be_pre_tool_use(self) -> None:
        # 登録ミスや将来追加された別eventのpayloadを、同じpolicyで黙って処理しない。
        item = payload("Bash", {"command": "pwd"})
        item["hook_event_name"] = "PostToolUse"
        self.assertEqual(outcome(hook.evaluate_input(item, {}))[0], "deny")

    def test_unknown_tool_is_denied(self) -> None:
        self.assertEqual(outcome(hook.evaluate_input(payload("Read", {}), {}))[0], "deny")

    def test_tool_input_must_be_object(self) -> None:
        self.assertEqual(outcome(hook.evaluate_input(payload("Bash", "pwd"), {}))[0], "deny")

    def test_known_optional_top_level_fields_are_accepted(self) -> None:
        item = payload(
            "Bash",
            {"command": "pwd"},
            prompt_id="prompt-test",
            effort={"level": "medium"},
            agent_id="agent-test",
            agent_type="reviewer",
        )
        self.assertEqual(outcome(hook.evaluate_input(item, {}))[0], "ask")

    def test_unknown_top_level_field_is_denied(self) -> None:
        item = payload("Bash", {"command": "pwd"}, unexpected="value")
        self.assertEqual(outcome(hook.evaluate_input(item, {}))[0], "deny")

    def test_unknown_tool_input_fields_are_denied(self) -> None:
        for item in (
            payload("Bash", {"command": "pwd", "unknown": False}),
            payload("WebFetch", {"url": "https://code.claude.com/docs", "prompt": "Summarize", "run_in_background": False}),
        ):
            with self.subTest(item=item):
                self.assertEqual(outcome(hook.evaluate_input(item, {}))[0], "deny")

    def test_tool_input_field_types_are_validated(self) -> None:
        # boolはintのsubclassなので、timeout=Trueをnumberとして通す回帰を明示的に防ぐ。
        for item in (
            payload("Bash", {"command": "pwd", "timeout": True}),
            payload("Bash", {"command": "pwd", "description": 1}),
            payload("WebFetch", {"url": "https://code.claude.com/docs", "prompt": False}),
        ):
            with self.subTest(item=item):
                self.assertEqual(outcome(hook.evaluate_input(item, {}))[0], "deny")

    def test_known_bash_optional_fields_are_accepted(self) -> None:
        # 拒否側だけでなく、既知任意fieldが正しい型ならAskへ進むことを固定する。
        item = payload(
            "Bash",
            {
                "command": "pwd",
                "description": "check",
                "timeout": 30,
                "run_in_background": False,
            },
        )
        self.assertEqual(outcome(hook.evaluate_input(item, {})), ("ask", hook.REASONS["ask"]))

    def test_bash_background_execution_is_denied(self) -> None:
        self.assertEqual(outcome(bash("pwd", run_in_background=True))[0], "deny")

    def test_caught_internal_exception_returns_fixed_deny_json(self) -> None:
        # 例外文には入力値が混ざり得るため、stdoutには固定reasonだけを返して
        # 構造化Denyと秘密情報の非露出を同時に保証する。
        stdin = io.StringIO(json.dumps(payload("Bash", {"command": "pwd"})))
        stdout = io.StringIO()
        with mock.patch.object(hook, "evaluate_input", side_effect=RuntimeError("sensitive input")):
            self.assertEqual(hook.run(stdin, stdout, {}), 0)
        self.assertEqual(outcome(json.loads(stdout.getvalue())), ("deny", hook.REASONS["internal"]))
        self.assertNotIn("sensitive input", stdout.getvalue())

    def test_stdout_contains_only_one_json_document(self) -> None:
        # debug用printやlogがstdoutへ混ざるとHook protocolを壊すため、JSON 1件に固定する。
        stdin = io.StringIO(json.dumps(payload("Bash", {"command": "pwd"})))
        stdout = io.StringIO()
        self.assertEqual(hook.run(stdin, stdout, {}), 0)
        self.assertEqual(stdout.getvalue().count("\n"), 1)
        self.assertEqual(outcome(json.loads(stdout.getvalue()))[0], "ask")

    def test_all_reasons_are_fixed_values(self) -> None:
        # reasonへの入力値埋め込みを防ぎ、文言の追加・変更を明示的なレビュー対象にする。
        expected = {
            "Canonical command requires approval",
            "Unregistered command",
            "Compound command not allowed",
            "Unsafe shell syntax",
            "Secret-like path",
            "Repository mismatch",
            "GitHub CLI option not allowed",
            "GitHub CLI environment override",
            "Host not allowed",
            "Unsafe URL",
            "Secret-like URL",
            "Malformed input",
            "Unsupported field",
            "Background execution not allowed",
            "Internal policy error",
        }
        self.assertEqual(set(hook.REASONS.values()), expected)


class GeneralBashTests(unittest.TestCase):
    def test_exact_general_commands_are_ask(self) -> None:
        for command in hook.EXACT_GENERAL_COMMANDS:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")

    def test_ls_canonical_forms_and_env_example_exception(self) -> None:
        # 例外はrepository rootの`.env.example`だけとし、`.env.local`や
        # 別directoryの同名fileへ例外判定が広がる回帰を防ぐ。
        for command in ("ls app", "ls -la tests", "ls .env.example"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("ls -a", "ls /tmp", "ls ../outside", "ls .env.local", "ls app tests"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_head_tail_and_wc_boundaries(self) -> None:
        # 最大200行の直内側と直外側を固定し、大量出力を許す方向への緩和を検出する。
        for command in ("head -n 1 README.md", "head -n 200 README.md", "tail -n 20 app/Models/Item.php", "wc -l README.md"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("head -n 0 README.md", "tail -n 201 README.md", "head README.md", "wc -l README.md CLAUDE.md"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_grep_canonical_forms(self) -> None:
        for command in ("grep TODO app", "grep -n Review app/Models/Review.php"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("grep -R TODO app", 'grep "two words" app', "grep TODO .env"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_find_canonical_forms_and_quote_requirement(self) -> None:
        # shell上で似たquote差も別入力として扱い、正規化による許可範囲拡大を防ぐ。
        for command in ('find app -type f', 'find tests -name "*.php"', 'find resources -type f -name "*.blade.php"'):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("find tests -name '*.php'", "find tests -name *.php", "find app -delete", "find app -exec echo x ;"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_sed_range_boundaries(self) -> None:
        # 200行ちょうどを許可し、201行と逆転rangeを拒否して出力上限を固定する。
        for command in ("sed -n '1,1p' README.md", "sed -n '1,200p' README.md", "sed -n '50,249p' README.md"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("sed -n '1,201p' README.md", "sed -n '5,4p' README.md", 'sed -n "1,20p" README.md', "sed -i 's/a/b/' README.md"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_echo_literal_and_secret_keyword(self) -> None:
        # echoも端末やlogへ秘密らしき文字列を露出し得るため、URLと同じkeyword方針を保つ。
        for command in ("echo Laravel", 'echo "test value"'):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("echo -n test", "echo $HOME", 'echo "api_key"', "echo value > output"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_dependency_read_commands_are_limited(self) -> None:
        for command in ("composer show laravel/framework", "npm list vite", "npm list @vitejs/plugin-vue"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("composer show --all", "composer install", "npm list --all", "npm install"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_quality_commands_require_single_safe_target(self) -> None:
        # レビュー対象外への全体実行を防ぎ、Pintは変更を行わない`--test`形だけに限定する。
        allowed = (
            "./vendor/bin/sail artisan test tests/Feature/ReviewTest.php",
            "./vendor/bin/sail php ./vendor/bin/phpstan analyse app/Models/Review.php",
            "./vendor/bin/sail php ./vendor/bin/pint app/Models/Review.php --test",
        )
        denied = (
            "./vendor/bin/sail artisan test",
            "./vendor/bin/sail artisan test app/Models/Review.php",
            "./vendor/bin/sail php ./vendor/bin/phpstan analyse",
            "./vendor/bin/sail php ./vendor/bin/pint app/Models/Review.php",
        )
        for command in allowed:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in denied:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_explicit_and_unregistered_commands_are_denied(self) -> None:
        for command in ("cat README.md", "awk x README.md", "python3 script.py", "php -r echo", "curl https://example.com", "mkdir tmp", "unknown-command"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_compound_redirect_substitution_and_control_syntax_are_denied(self) -> None:
        # 各shell構文は独立した迂回経路なので、1例へ集約せず個別の回帰を固定する。
        for command in (
            "pwd && pwd",
            "pwd || pwd",
            "pwd | head",
            "pwd |& head",
            "pwd; pwd",
            "pwd &",
            "echo test > out",
            "head -n 1 < input",
            "echo $(pwd)",
            "echo `pwd`",
            "pwd\npwd",
            "pwd\t",
            "pwd\x00",
            "HOME=/tmp pwd",
        ):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_secret_paths_are_denied_by_component_suffix_and_prefix(self) -> None:
        # directory prefix、component、suffix、完全file名の各判定を残し、
        # いずれか一方式が簡略化で失われる回帰を検出する。
        for path in (
            ".env",
            "config/.env.example",
            "storage/logs/app.log",
            "storage/framework/cache/data",
            "storage/app/private/file.txt",
            "tmp/sessions/data",
            "backup/data.txt",
            "keys/client.pem",
            "database/data.sqlite",
            "keys/id_rsa",
            "config/credentials/value.txt",
        ):
            with self.subTest(path=path):
                self.assertEqual(outcome(bash(f"ls {path}"))[0], "deny")


class GitTests(unittest.TestCase):
    def test_all_ten_git_shapes_are_ask(self) -> None:
        # 現行の文書とSkillが必要とする最小10形を固定し、Ask対象の無断増減を防ぐ。
        commands = (
            "git status --short",
            "git branch --show-current",
            "git branch -a",
            "git diff",
            "git diff --check",
            "git diff -- README.md",
            "git diff --cached -- README.md",
            "git diff HEAD -- README.md",
            "git log --oneline -n 50",
            "git grep Review -- app",
        )
        for command in commands:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")

    def test_git_log_boundaries(self) -> None:
        # 1〜50件の直内側・直外側を確認し、大量の履歴取得へ範囲が広がるのを防ぐ。
        for command in ("git log --oneline -n 1", "git log --oneline -n 50"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in ("git log --oneline -n 0", "git log --oneline -n 51", "git log --oneline", "git log -n 1 --oneline"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_git_path_and_grep_boundaries(self) -> None:
        # `:(top)`等のGit独自pathspecを単純相対pathとして扱う迂回を防ぐ。
        for command in (
            "git diff --",
            "git diff -- ../outside",
            "git diff -- '*.php'",
            "git diff -- :(top)README.md",
            "git diff main -- README.md",
            'git grep "two words" -- app',
            "git grep -E -- app",
            "git grep Review -- app tests",
            "git grep Review -- .env",
        ):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_unregistered_and_modifying_git_are_denied(self) -> None:
        # `git show`は読み取り形でも未登録、`git fetch`は通信とref更新を伴うため、
        # command名だけから安全性を推測せずclosed worldを維持する。
        for command in ("git show", "git status", "git add README.md", "git commit", "git fetch", "git reset"):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")


class GitHubCliTests(unittest.TestCase):
    def test_list_canonical_forms_and_boundaries(self) -> None:
        # list系の`--repo`末尾とoption順を固定し、汎用option parserへの拡張を避ける。
        for command in (
            f"gh issue list --state open --limit 1 --repo {hook.REPOSITORY}",
            f"gh pr list --state all --limit 100 --repo {hook.REPOSITORY}",
        ):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")
        for command in (
            f"gh issue list --state open --limit 0 --repo {hook.REPOSITORY}",
            f"gh pr list --state merged --limit 20 --repo {hook.REPOSITORY}",
            f"gh issue list --repo {hook.REPOSITORY} --state open --limit 20",
        ):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_view_comments_json_and_checks_canonical_forms(self) -> None:
        commands = (
            f"gh issue view 51 --repo {hook.REPOSITORY}",
            f"gh issue view 51 --repo {hook.REPOSITORY} --comments",
            f"gh issue view 51 --repo {hook.REPOSITORY} --json number,title,state,body,comments,labels,url",
            f"gh pr view 43 --repo {hook.REPOSITORY}",
            f"gh pr view 43 --repo {hook.REPOSITORY} --comments",
            f"gh pr view 43 --repo {hook.REPOSITORY} --json number,title,files,statusCheckRollup,url",
            f"gh pr checks 43 --repo {hook.REPOSITORY}",
        )
        for command in commands:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "ask")

    def test_noncanonical_options_and_unknown_fields_are_denied(self) -> None:
        # 個別に認めたoptionでも未登録の組み合わせは拒否し、組み合わせ爆発を防ぐ。
        # JSON fieldもallowlist外へ暗黙に拡張しない。
        commands = (
            f"gh issue view 51 --json number --repo {hook.REPOSITORY}",
            f"gh issue view 51 --repo {hook.REPOSITORY} --comments --json number",
            f"gh issue view 51 --repo {hook.REPOSITORY} --json number,unknown",
            f"gh pr view 43 --repo {hook.REPOSITORY} --jq .title",
            f"gh pr checks 43 --repo {hook.REPOSITORY} --watch",
            f"gh issue view 51 --repo={hook.REPOSITORY}",
        )
        for command in commands:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_issue_and_pr_json_fields_are_kept_separate(self) -> None:
        # Issue用fieldとPR用fieldを取り違えても検出できるよう、相互の専用fieldを拒否する。
        commands = (
            f"gh issue view 51 --repo {hook.REPOSITORY} --json files",
            f"gh pr view 43 --repo {hook.REPOSITORY} --json labels",
        )
        for command in commands:
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command)), ("deny", hook.REASONS["gh_option"]))

    def test_repository_mismatch_and_unregistered_commands_are_denied(self) -> None:
        for command in (
            "gh issue view 51 --repo github.com/other/repository",
            "gh issue status",
            "gh pr status",
            f"gh issue edit 51 --repo {hook.REPOSITORY}",
            "gh api repos/example/example",
        ):
            with self.subTest(command=command):
                self.assertEqual(outcome(bash(command))[0], "deny")

    def test_gh_environment_overrides_are_denied_only_for_gh(self) -> None:
        # GH環境変数はghの暗黙接続先だけを制限し、pwdやGitまで止める過剰Denyを防ぐ。
        # `gh --version`も同じgh境界に置き、dispatch順の例外を作らない。
        for name in ("GH_REPO", "GH_HOST"):
            with self.subTest(name=name):
                self.assertEqual(
                    outcome(bash(f"gh issue view 51 --repo {hook.REPOSITORY}", {name: "synthetic"}))[0],
                    "deny",
                )
                self.assertEqual(outcome(bash("pwd", {name: "synthetic"}))[0], "ask")
                self.assertEqual(outcome(bash("git status --short", {name: "synthetic"}))[0], "ask")
                self.assertEqual(outcome(bash("gh --version", {name: "synthetic"}))[0], "deny")

    def test_gh_environment_prefix_is_denied_without_exposing_value(self) -> None:
        # override値に機密情報が含まれても、専用の固定reason以外へ露出させない。
        result = bash(f"GH_REPO=synthetic gh issue view 51 --repo {hook.REPOSITORY}")
        self.assertEqual(outcome(result), ("deny", hook.REASONS["gh_environment"]))
        self.assertNotIn("synthetic", json.dumps(result))


class WebFetchTests(unittest.TestCase):
    def test_all_fourteen_hosts_are_ask(self) -> None:
        # 設計書の初期14hostと件数を同期し、意図しない追加・削除を顕在化させる。
        self.assertEqual(len(hook.WEBFETCH_HOSTS), 14)
        for host in hook.WEBFETCH_HOSTS:
            with self.subTest(host=host):
                self.assertEqual(outcome(webfetch(f"https://{host}/docs"))[0], "ask")

    def test_scheme_host_suffix_and_subdomain_checks(self) -> None:
        # suffix偽装、未登録subdomain、末尾dotは別々のhost回避表現として固定する。
        for url in (
            "http://code.claude.com/docs",
            "https://example.com/docs",
            "https://code.claude.com.example.com/docs",
            "https://sub.code.claude.com/docs",
            "https://code.claude.com./docs",
        ):
            with self.subTest(url=url):
                self.assertEqual(outcome(webfetch(url))[0], "deny")

    def test_port_userinfo_and_malformed_url_are_denied(self) -> None:
        # `:443`も例外化せず、意味上の同一性よりcanonical URL表記を一つに固定する。
        for url in (
            "https://code.claude.com:443/docs",
            "https://user@code.claude.com/docs",
            "https://code.claude.com:bad/docs",
            "not-a-url",
        ):
            with self.subTest(url=url):
                self.assertEqual(outcome(webfetch(url))[0], "deny")

    def test_safe_query_fragment_and_prompt_keyword_are_ask(self) -> None:
        # promptの`token`は公式用語の調査にも現れるためDenyせず、人間確認のAskに留める。
        for url, prompt in (
            ("https://code.claude.com/docs?q=hooks", "Summarize"),
            ("https://code.claude.com/docs#hooks", "Summarize"),
            ("https://code.claude.com/docs", "Explain token handling"),
        ):
            with self.subTest(url=url, prompt=prompt):
                self.assertEqual(outcome(webfetch(url, prompt))[0], "ask")

    def test_url_secret_keywords_and_one_decode_are_denied(self) -> None:
        # URLは外部記録され得るためkeywordをDenyし、1回decode後の表記も検出する。
        # 2重encodeのAskは安全保証ではなく、Hook独自解釈を1回に限定する境界を固定する。
        for url in (
            "https://code.claude.com/token-guide",
            "https://code.claude.com/docs?api_key=dummy",
            "https://code.claude.com/%74oken-guide",
            "https://code.claude.com/docs#private_key",
        ):
            with self.subTest(url=url):
                self.assertEqual(outcome(webfetch(url))[0], "deny")
        self.assertEqual(outcome(webfetch("https://code.claude.com/%2574oken-guide"))[0], "ask")

    def test_prompt_control_characters_are_denied(self) -> None:
        # URLだけでなくprompt側の制御文字も拒否し、未検査の複数行入力をAskへ流さない。
        result = webfetch("https://code.claude.com/docs", "line1\nline2")
        self.assertEqual(outcome(result), ("deny", hook.REASONS["url"]))

    def test_webfetch_unknown_field_is_denied(self) -> None:
        self.assertEqual(
            outcome(webfetch("https://code.claude.com/docs", run_in_background=False))[0],
            "deny",
        )


if __name__ == "__main__":
    unittest.main()
