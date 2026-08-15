"""共用ローカル成果物helperのpure validation回帰テスト。"""

from __future__ import annotations

import base64
import errno
import hashlib
import importlib.util
import io
import multiprocessing
import os
from pathlib import Path
import signal
import stat
import tempfile
import types
import unicodedata
import unittest
from unittest import mock


HELPER_PATH = (
    Path(__file__).resolve().parents[2]
    / "skills"
    / "save-local-artifact"
    / "scripts"
    / "save_local_artifact.py"
)
SPEC = importlib.util.spec_from_file_location("save_local_artifact", HELPER_PATH)
assert SPEC is not None and SPEC.loader is not None
helper = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(helper)


def encode(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def expected_digest(category: str, filename: str, normalized: bytes) -> str:
    digest_input = (
        b"review-app-laravel/save-local-artifact/v1\x00"
        + category.encode("ascii")
        + b"\x00"
        + filename.encode("ascii")
        + b"\x00"
        + normalized
    )
    return hashlib.sha256(digest_input).hexdigest()


def expected_preflight_output(category: str, filename: str, normalized: bytes) -> bytes:
    digest = expected_digest(category, filename, normalized)
    return (
        (
            f"category: {category}\n"
            f"filename: {filename}\n"
            f"normalized-byte-count: {len(normalized)}\n"
            f"confirmation-digest: {digest}\n"
            "----- BEGIN NORMALIZED CONTENT -----\n"
        ).encode("ascii")
        + normalized
        + b"----- END NORMALIZED CONTENT -----\n"
    )


class CapturedStdout:
    def __init__(self) -> None:
        self.buffer = io.BytesIO()


def run_main(argv: list[str]) -> tuple[int, bytes, str]:
    stdout = CapturedStdout()
    stderr = io.StringIO()
    with (
        mock.patch("sys.stdout", stdout),
        mock.patch("sys.stderr", stderr),
    ):
        result = helper.main(argv)
    return result, stdout.buffer.getvalue(), stderr.getvalue()


def save_argv(
    category: str = "reports",
    filename: str = "a.md",
    content: bytes = b"content",
    digest: str | None = None,
) -> list[str]:
    confirmation_digest = digest or expected_digest(category, filename, content)
    return [
        "save",
        "--category",
        category,
        "--filename",
        filename,
        "--confirmation-digest",
        confirmation_digest,
        f"--content-base64url={encode(content)}",
    ]


class FakeEntry:
    def __init__(self, name: str, error: Exception | None = None) -> None:
        self.name = name
        self.error = error

    def stat(self, *, follow_symlinks: bool):
        if self.error is not None:
            raise self.error
        return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600)


class FakeScandir:
    def __init__(self, entries) -> None:
        self.entries = entries

    def __enter__(self):
        return iter(self.entries)

    def __exit__(self, exception_type, exception, traceback) -> None:
        return None


def atomic_worker(
    directory: str,
    filename: str,
    content: bytes,
    start_event,
    result_queue,
) -> None:
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        start_event.wait()
        result = helper.atomic_publish(directory_fd, filename, content)
        result_queue.put((result.state.value, result.error_code))
    finally:
        os.close(directory_fd)


def staging_kill_worker(directory: str, ready_connection) -> None:
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    staging = helper.create_staging_file(directory_fd)
    ready_connection.send(staging.name)
    ready_connection.close()
    signal.pause()


def patch_cwd_inode_to(root: Path):
    original_stat = os.stat

    def stat_with_fixture_cwd(path, *args, **kwargs):
        if path == ".":
            return original_stat(root)
        return original_stat(path, *args, **kwargs)

    return mock.patch.object(helper.os, "stat", side_effect=stat_with_fixture_cwd)


class ValidationTestCase(unittest.TestCase):
    def assert_validation_error(self, code: str, function, *args) -> None:
        with self.assertRaises(helper.ValidationError) as caught:
            function(*args)
        self.assertEqual(caught.exception.code, code)
        self.assertEqual(str(caught.exception), code)


class CategoryAndFilenameTests(ValidationTestCase):
    def test_categories_are_closed_world(self) -> None:
        for category in ("reports", "handoffs", "scratch"):
            with self.subTest(category=category):
                self.assertEqual(helper.validate_category(category), category)
        for category in ("", "unknown"):
            with self.subTest(category=category):
                self.assert_validation_error(
                    helper.ERR_CATEGORY, helper.validate_category, category
                )

    def test_valid_filenames_include_the_67_byte_boundary(self) -> None:
        filenames = ("a.md", "a.txt", "A-1_test.md", "a" * 63 + ".txt")
        for filename in filenames:
            with self.subTest(filename=filename):
                self.assertEqual(helper.validate_filename(filename), filename)
        self.assertEqual(len(filenames[-1].encode("ascii")), 67)

    def test_invalid_filenames_are_rejected(self) -> None:
        filenames = (
            ".md",
            "../a.md",
            "a/b.md",
            "a\\b.md",
            "a b.md",
            "a..md",
            "a.tar.md",
            "a.MD",
            "日本語.md",
            ".hidden.md",
            "a" * 64 + ".txt",
            "a\x00.md",
        )
        for filename in filenames:
            with self.subTest(filename=filename):
                self.assert_validation_error(
                    helper.ERR_FILENAME, helper.validate_filename, filename
                )
        self.assertEqual(len(filenames[-2].encode("ascii")), 68)


class Base64UrlAndSizeTests(ValidationTestCase):
    def test_canonical_base64url_accepts_expected_content(self) -> None:
        values = (b"", b"a", b"ab", b"abc", b"ASCII text", "日本語".encode())
        for raw in values:
            with self.subTest(raw=raw):
                self.assertEqual(helper.decode_canonical_base64url(encode(raw)), raw)

    def test_maximum_encoded_boundary_decodes_to_maximum_raw_size(self) -> None:
        raw = b"a" * helper.MAX_RAW_BYTES
        payload = encode(raw)
        self.assertEqual(len(payload), helper.MAX_ENCODED_BYTES)
        self.assertEqual(helper.decode_canonical_base64url(payload), raw)

    def test_invalid_alphabet_padding_whitespace_and_pad_bits_are_rejected(
        self,
    ) -> None:
        payloads = (
            "YQ=",
            "+w",
            "/w",
            "Y Q",
            "Y\tQ",
            "Y\nQ",
            "Y\rQ",
            "Y.Q",
            "AB",  # AAと同じbyteへdecodeされるnon-zero pad bits表現
        )
        for payload in payloads:
            with self.subTest(payload=payload):
                self.assert_validation_error(
                    helper.ERR_CONTENT_BASE64URL,
                    helper.decode_canonical_base64url,
                    payload,
                )

    def test_encoded_size_above_limit_is_rejected_before_decode(self) -> None:
        payload = "A" * (helper.MAX_ENCODED_BYTES + 1)
        self.assert_validation_error(
            helper.ERR_CONTENT_ENCODED_SIZE,
            helper.decode_canonical_base64url,
            payload,
        )

    def test_raw_size_boundaries_are_independently_enforced(self) -> None:
        for size in (0, 1, helper.MAX_RAW_BYTES):
            with self.subTest(size=size):
                helper._validate_raw_size(b"a" * size)
        self.assert_validation_error(
            helper.ERR_CONTENT_RAW_SIZE,
            helper._validate_raw_size,
            b"a" * (helper.MAX_RAW_BYTES + 1),
        )

    def test_normalized_size_boundaries_are_independently_enforced(self) -> None:
        helper._validate_normalized_size(b"a" * helper.MAX_NORMALIZED_BYTES)
        self.assert_validation_error(
            helper.ERR_CONTENT_NORMALIZED_SIZE,
            helper._validate_normalized_size,
            b"a" * (helper.MAX_NORMALIZED_BYTES + 1),
        )

    def test_raw_limit_applies_before_crlf_can_shrink_content(self) -> None:
        raw = b"\r\n" * (helper.MAX_RAW_BYTES // 2) + b"x"
        self.assertEqual(len(raw), helper.MAX_RAW_BYTES + 1)
        self.assert_validation_error(
            helper.ERR_CONTENT_RAW_SIZE, helper.normalize_and_validate_content, raw
        )


class TextValidationTests(ValidationTestCase):
    def test_valid_ascii_japanese_empty_tab_and_lf_are_accepted(self) -> None:
        values = (b"", b"ASCII", "日本語".encode(), b"a\tb\nc")
        for raw in values:
            with self.subTest(raw=raw):
                self.assertEqual(
                    helper.decode_normalize_validate_content(encode(raw)), raw
                )

    def test_invalid_utf8_is_rejected_strictly(self) -> None:
        self.assert_validation_error(
            helper.ERR_CONTENT_UTF8,
            helper.decode_normalize_validate_content,
            encode(b"\xff"),
        )

    def test_line_endings_are_normalized_without_changing_trailing_lf(self) -> None:
        cases = (
            (b"a\nb", b"a\nb"),
            (b"a\r\nb", b"a\nb"),
            (b"a\rb", b"a\nb"),
            (b"a\r\nb\rc\nd", b"a\nb\nc\nd"),
            (b"with-newline\n", b"with-newline\n"),
            (b"without-newline", b"without-newline"),
        )
        for raw, expected in cases:
            with self.subTest(raw=raw):
                self.assertEqual(
                    helper.decode_normalize_validate_content(encode(raw)), expected
                )

    def test_forbidden_c0_characters_are_rejected_except_tab_lf_and_cr(self) -> None:
        for code_point in range(0x20):
            if code_point in {0x09, 0x0A, 0x0D}:
                continue
            raw = f"a{chr(code_point)}b".encode()
            with self.subTest(code_point=code_point):
                self.assert_validation_error(
                    helper.ERR_CONTENT_CHARACTER,
                    helper.decode_normalize_validate_content,
                    encode(raw),
                )

    def test_forbidden_del_c1_and_special_unicode_are_rejected(self) -> None:
        for code_point in (0x1B, 0x7F, *range(0x80, 0xA0), 0xFEFF, 0x2028, 0x2029):
            raw = f"a{chr(code_point)}b".encode()
            with self.subTest(code_point=code_point):
                self.assert_validation_error(
                    helper.ERR_CONTENT_CHARACTER,
                    helper.decode_normalize_validate_content,
                    encode(raw),
                )

    def test_unicode_normalization_is_not_applied(self) -> None:
        nfc = unicodedata.normalize("NFC", "e\u0301").encode()
        nfd = unicodedata.normalize("NFD", "é").encode()
        self.assertNotEqual(nfc, nfd)
        self.assertEqual(helper.decode_normalize_validate_content(encode(nfc)), nfc)
        self.assertEqual(helper.decode_normalize_validate_content(encode(nfd)), nfd)

    def test_normalized_maximum_is_accepted(self) -> None:
        raw = b"a" * helper.MAX_NORMALIZED_BYTES
        self.assertEqual(helper.decode_normalize_validate_content(encode(raw)), raw)


class ConfirmationDigestTests(unittest.TestCase):
    def test_digest_is_deterministic_lowercase_sha256(self) -> None:
        values = [
            helper.compute_confirmation_digest("reports", "a.md", b"content")
            for _ in range(2)
        ]
        self.assertEqual(values[0], values[1])
        self.assertRegex(values[0], r"\A[0-9a-f]{64}\Z")

    def test_category_filename_and_content_are_each_bound(self) -> None:
        baseline = helper.compute_confirmation_digest("reports", "a.md", b"content")
        variants = (
            helper.compute_confirmation_digest("scratch", "a.md", b"content"),
            helper.compute_confirmation_digest("reports", "b.md", b"content"),
            helper.compute_confirmation_digest("reports", "a.md", b"changed"),
        )
        for digest in variants:
            with self.subTest(digest=digest):
                self.assertNotEqual(digest, baseline)

    def test_digest_matches_independently_assembled_versioned_vector(self) -> None:
        actual = helper.compute_confirmation_digest("handoffs", "A-1.txt", b"x\ny")
        self.assertEqual(actual, expected_digest("handoffs", "A-1.txt", b"x\ny"))

    def test_digest_is_not_an_ambiguous_simple_concatenation(self) -> None:
        actual = helper.compute_confirmation_digest("reports", "a.md", b"content")
        simple_concatenation = hashlib.sha256(
            b"review-app-laravel/save-local-artifact/v1reportsa.mdcontent"
        ).hexdigest()
        self.assertNotEqual(actual, simple_concatenation)
        self.assertEqual(actual, expected_digest("reports", "a.md", b"content"))


class TrustedPreflightTests(unittest.TestCase):
    def test_ascii_japanese_tab_lf_crlf_and_empty_succeed(self) -> None:
        cases = (
            (b"ASCII", b"ASCII"),
            ("日本語".encode(), "日本語".encode()),
            (b"a\tb", b"a\tb"),
            (b"a\nb", b"a\nb"),
            (b"a\r\nb", b"a\nb"),
            (b"", b""),
        )
        for raw, normalized in cases:
            with self.subTest(raw=raw):
                result, stdout, stderr = run_main(
                    [
                        "preflight",
                        "--category",
                        "reports",
                        "--filename",
                        "a.md",
                        f"--content-base64url={encode(raw)}",
                    ]
                )
                self.assertEqual(result, 0)
                self.assertEqual(
                    stdout, expected_preflight_output("reports", "a.md", normalized)
                )
                self.assertEqual(stderr, "")

    def test_crlf_digest_binds_normalized_not_raw_content(self) -> None:
        result, stdout, stderr = run_main(
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                f"--content-base64url={encode(b'a\r\nb')}",
            ]
        )
        self.assertEqual(result, 0)
        self.assertEqual(stdout, expected_preflight_output("reports", "a.md", b"a\nb"))
        self.assertNotIn(expected_digest("reports", "a.md", b"a\r\nb").encode(), stdout)
        self.assertEqual(stderr, "")

    def test_trailing_lf_changes_count_digest_and_framed_output(self) -> None:
        outputs = []
        for raw in (b"abc", b"abc\n"):
            result, stdout, stderr = run_main(
                [
                    "preflight",
                    "--category",
                    "reports",
                    "--filename",
                    "a.md",
                    f"--content-base64url={encode(raw)}",
                ]
            )
            self.assertEqual(result, 0)
            self.assertEqual(stdout, expected_preflight_output("reports", "a.md", raw))
            self.assertEqual(stderr, "")
            outputs.append(stdout)

        self.assertNotEqual(outputs[0], outputs[1])
        self.assertIn(b"normalized-byte-count: 3\n", outputs[0])
        self.assertIn(b"normalized-byte-count: 4\n", outputs[1])
        self.assertIn(b"\nabc----- END NORMALIZED CONTENT -----\n", outputs[0])
        self.assertIn(b"\nabc\n----- END NORMALIZED CONTENT -----\n", outputs[1])

    def test_empty_content_has_no_placeholder(self) -> None:
        result, stdout, stderr = run_main(
            [
                "preflight",
                "--category",
                "scratch",
                "--filename",
                "empty.txt",
                "--content-base64url=",
            ]
        )
        self.assertEqual(result, 0)
        self.assertEqual(stdout, expected_preflight_output("scratch", "empty.txt", b""))
        self.assertIn(b"normalized-byte-count: 0\n", stdout)
        self.assertEqual(stderr, "")

    def test_delimiter_text_is_allowed_and_bound_as_content(self) -> None:
        content = (
            b"----- BEGIN NORMALIZED CONTENT -----\n"
            b"body\n"
            b"----- END NORMALIZED CONTENT -----"
        )
        result, stdout, stderr = run_main(
            [
                "preflight",
                "--category",
                "handoffs",
                "--filename",
                "framing.md",
                f"--content-base64url={encode(content)}",
            ]
        )
        self.assertEqual(result, 0)
        self.assertEqual(
            stdout, expected_preflight_output("handoffs", "framing.md", content)
        )
        self.assertIn(
            expected_digest("handoffs", "framing.md", content).encode(), stdout
        )
        self.assertEqual(stderr, "")


class ArgumentSchemaTests(ValidationTestCase):
    def test_canonical_preflight_schema_is_parsed(self) -> None:
        command = helper.parse_command(
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                "--content-base64url=",
            ]
        )
        self.assertEqual(
            command,
            helper.ParsedCommand("preflight", "reports", "a.md", None, ""),
        )

    def test_noncanonical_and_unimplemented_schemas_are_rejected(self) -> None:
        values = (
            [],
            [
                "preflight",
                "--filename",
                "a.md",
                "--category",
                "reports",
                "--content-base64url=",
            ],
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                "--content-base64url",
                "",
            ],
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                "--content-base64url=",
                "--extra",
            ],
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                None,
            ],
        )
        for argv in values:
            with self.subTest(argv=argv):
                self.assert_validation_error(
                    helper.ERR_ARGUMENT_SCHEMA, helper.parse_command, argv
                )

    def test_canonical_save_schema_is_parsed_including_empty_payload(self) -> None:
        digest = expected_digest("reports", "a.md", b"")
        command = helper.parse_command(save_argv(content=b"", digest=digest))
        self.assertEqual(
            command,
            helper.ParsedCommand("save", "reports", "a.md", digest, ""),
        )

    def test_invalid_save_schema_and_digest_are_rejected(self) -> None:
        invalid_argv = (
            save_argv(digest="A" * 64),
            save_argv(digest="a" * 63),
            save_argv(digest="a" * 65),
            save_argv() + ["--extra"],
            [*save_argv()[:5], "--content-base64url=", *save_argv()[5:]],
            [*save_argv()[:5], "--confirmation-digest", "a" * 64, "--extra"],
        )
        for argv in invalid_argv:
            with self.subTest(argv=argv):
                with self.assertRaises(helper.ValidationError):
                    helper.parse_command(argv)

        result, stdout, stderr = run_main(["save"])
        self.assertEqual(result, 1)
        self.assertEqual(stdout, b"")
        self.assertEqual(
            stderr,
            helper.build_save_error_output(
                helper.PublishResult(
                    helper.PublishState.FAILED, helper.ERR_ARGUMENT_SCHEMA
                )
            ),
        )

    def test_main_reports_valid_preflight_as_success(self) -> None:
        payload = encode(b"synthetic private value")
        result, stdout, stderr = run_main(
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                f"--content-base64url={payload}",
            ]
        )
        self.assertEqual(result, 0)
        self.assertEqual(
            stdout,
            expected_preflight_output("reports", "a.md", b"synthetic private value"),
        )
        self.assertEqual(stderr, "")
        self.assertNotIn(payload.encode(), stdout)

    def test_main_returns_only_fixed_validation_code(self) -> None:
        payload = "invalid payload"
        result, stdout, stderr = run_main(
            [
                "preflight",
                "--category",
                "reports",
                "--filename",
                "a.md",
                f"--content-base64url={payload}",
            ]
        )
        self.assertEqual(result, 1)
        self.assertEqual(stdout, b"")
        self.assertEqual(stderr, helper.ERR_CONTENT_BASE64URL + "\n")
        self.assertNotIn(payload, stderr)

    def test_main_converts_unexpected_exception_to_fixed_error(self) -> None:
        stdout = CapturedStdout()
        stderr = io.StringIO()
        with (
            mock.patch("sys.stdout", stdout),
            mock.patch("sys.stderr", stderr),
            mock.patch.object(
                helper, "parse_command", side_effect=RuntimeError("synthetic")
            ),
        ):
            result = helper.main([])
        self.assertEqual(result, 1)
        self.assertEqual(stdout.buffer.getvalue(), b"")
        self.assertEqual(stderr.getvalue(), helper.ERR_INTERNAL + "\n")
        self.assertNotIn("synthetic", stderr.getvalue())


class SaveModeTests(unittest.TestCase):
    def test_digest_mismatch_prevents_all_filesystem_work(self) -> None:
        baseline = expected_digest("reports", "a.md", b"content")
        cases = (
            save_argv(category="scratch", digest=baseline),
            save_argv(filename="b.md", digest=baseline),
            save_argv(content=b"changed", digest=baseline),
        )
        for argv in cases:
            with (
                self.subTest(argv=argv),
                mock.patch.object(helper, "execute_save") as execute_save,
            ):
                result, stdout, stderr = run_main(argv)
                self.assertEqual(result, 1)
                self.assertEqual(stdout, b"")
                self.assertEqual(
                    stderr,
                    helper.build_save_error_output(
                        helper.PublishResult(
                            helper.PublishState.FAILED,
                            helper.ERR_CONFIRMATION_MISMATCH,
                        )
                    ),
                )
                execute_save.assert_not_called()

    def test_preflight_never_enters_save_filesystem_boundary(self) -> None:
        with mock.patch.object(helper, "execute_save") as execute_save:
            result, _, stderr = run_main(
                [
                    "preflight",
                    "--category",
                    "reports",
                    "--filename",
                    "a.md",
                    f"--content-base64url={encode(b'content')}",
                ]
            )
        self.assertEqual(result, 0)
        self.assertEqual(stderr, "")
        execute_save.assert_not_called()

    def test_save_success_output_contains_no_content_or_payload(self) -> None:
        content = b"synthetic save body"
        argv = save_argv(content=content)
        with mock.patch.object(
            helper,
            "execute_save",
            return_value=helper.PublishResult(helper.PublishState.COMPLETE),
        ):
            result, stdout, stderr = run_main(argv)
        self.assertEqual(result, 0)
        self.assertEqual(
            stdout,
            helper.build_save_success_output(
                "reports",
                "a.md",
                len(content),
                expected_digest("reports", "a.md", content),
            ),
        )
        self.assertNotIn(content, stdout)
        self.assertNotIn(encode(content).encode(), stdout)
        self.assertEqual(stderr, "")

    def test_save_failure_uses_only_fixed_status_code_and_reason(self) -> None:
        publish_result = helper.PublishResult(
            helper.PublishState.INDETERMINATE, helper.ERR_PUBLISH_FSYNC
        )
        with mock.patch.object(helper, "execute_save", return_value=publish_result):
            result, stdout, stderr = run_main(save_argv())
        self.assertEqual(result, 1)
        self.assertEqual(stdout, b"")
        self.assertEqual(stderr, helper.build_save_error_output(publish_result))


class RootAndRuntimeTests(ValidationTestCase):
    def _helper_layout(self, root: Path) -> Path:
        scripts = root / ".claude/skills/save-local-artifact/scripts"
        scripts.mkdir(parents=True)
        helper_path = scripts / "save_local_artifact.py"
        helper_path.write_text("# fixture\n", encoding="utf-8")
        return helper_path

    def test_fixed_depth_root_success_and_cwd_inode_match(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            helper_path = self._helper_layout(root)
            self.assertEqual(helper.derive_repository_root(helper_path, cwd=root), root)

    def test_unexpected_component_and_cwd_mismatch_are_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            helper_path = self._helper_layout(root)
            wrong_path = helper_path.parent.parent / "unexpected" / helper_path.name
            with tempfile.TemporaryDirectory() as other:
                self.assert_validation_error(
                    helper.ERR_REPOSITORY_ROOT,
                    helper.derive_repository_root,
                    wrong_path,
                    root,
                )
                self.assert_validation_error(
                    helper.ERR_REPOSITORY_ROOT,
                    helper.derive_repository_root,
                    helper_path,
                    other,
                )

    def test_helper_ancestor_symlink_and_dangling_symlink_are_rejected(self) -> None:
        for dangling in (False, True):
            with (
                self.subTest(dangling=dangling),
                tempfile.TemporaryDirectory() as temporary,
            ):
                root = Path(temporary)
                skill = root / ".claude/skills/save-local-artifact"
                skill.mkdir(parents=True)
                target = skill / "real-scripts"
                if not dangling:
                    target.mkdir()
                    (target / "save_local_artifact.py").write_text(
                        "# fixture\n", encoding="utf-8"
                    )
                (skill / "scripts").symlink_to(target.name, target_is_directory=True)
                helper_path = skill / "scripts/save_local_artifact.py"
                self.assert_validation_error(
                    helper.ERR_REPOSITORY_ROOT,
                    helper.derive_repository_root,
                    helper_path,
                    root,
                )

    def test_runtime_accepts_wsl_markers(self) -> None:
        for release in (b"5.15.0-Microsoft-standard-WSL2", b"linux-wsl-test"):
            with self.subTest(release=release):
                helper.validate_runtime(
                    Path("/home/user/repository"),
                    platform_name="linux",
                    osrelease_reader=lambda release=release: release,
                    capability_checker=lambda: True,
                    realpath=lambda _: "/home/user/repository",
                )

    def test_current_python_exposes_required_filesystem_capabilities(self) -> None:
        self.assertTrue(helper._has_required_api_capabilities())

    def test_runtime_rejects_platform_release_mount_and_api_failures(self) -> None:
        cases = (
            {"platform_name": "darwin"},
            {"osrelease_reader": mock.Mock(side_effect=OSError("synthetic"))},
            {"osrelease_reader": lambda: b"generic-linux"},
            {"realpath": lambda _: "/mnt/c"},
            {"realpath": lambda _: "/mnt/c/"},
            {"realpath": lambda _: "/mnt/c/project"},
            {"capability_checker": lambda: False},
        )
        for overrides in cases:
            arguments = {
                "platform_name": "linux",
                "osrelease_reader": lambda: b"Microsoft WSL2",
                "capability_checker": lambda: True,
                "realpath": lambda _: "/home/user/repository",
            }
            arguments.update(overrides)
            with (
                self.subTest(overrides=overrides),
                self.assertRaises(helper.ValidationError),
            ):
                helper.validate_runtime(Path("/home/user/repository"), **arguments)


class DirectoryValidationTests(ValidationTestCase):
    def test_normal_ai_work_and_category_return_category_fd(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            category = root / ".ai-work/reports"
            category.mkdir(parents=True, mode=0o700)
            with patch_cwd_inode_to(root):
                directory_fd = helper.open_category_directory(root, "reports")
            try:
                self.assertTrue(stat.S_ISDIR(os.fstat(directory_fd).st_mode))
            finally:
                os.close(directory_fd)

    def test_missing_regular_symlink_and_dangling_category_are_rejected(self) -> None:
        kinds = ("missing", "regular", "symlink", "dangling")
        for kind in kinds:
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                ai_work = root / ".ai-work"
                ai_work.mkdir(mode=0o700)
                category = ai_work / "reports"
                if kind == "regular":
                    category.write_bytes(b"fixture")
                elif kind == "symlink":
                    target = ai_work / "target"
                    target.mkdir()
                    category.symlink_to(target.name, target_is_directory=True)
                elif kind == "dangling":
                    category.symlink_to("missing", target_is_directory=True)
                with patch_cwd_inode_to(root):
                    self.assert_validation_error(
                        helper.ERR_DIRECTORY,
                        helper.open_category_directory,
                        root,
                        "reports",
                    )

    def test_missing_regular_symlink_and_dangling_ai_work_are_rejected(self) -> None:
        kinds = ("missing", "regular", "symlink", "dangling")
        for kind in kinds:
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                ai_work = root / ".ai-work"
                if kind == "regular":
                    ai_work.write_bytes(b"fixture")
                elif kind == "symlink":
                    target = root / "target"
                    (target / "reports").mkdir(parents=True)
                    ai_work.symlink_to(target.name, target_is_directory=True)
                elif kind == "dangling":
                    ai_work.symlink_to("missing", target_is_directory=True)
                with patch_cwd_inode_to(root):
                    self.assert_validation_error(
                        helper.ERR_DIRECTORY,
                        helper.open_category_directory,
                        root,
                        "reports",
                    )

    def test_owner_and_group_other_write_bits_are_rejected(self) -> None:
        base_mode = stat.S_IFDIR | 0o700
        metadata = (
            types.SimpleNamespace(st_mode=base_mode, st_uid=os.geteuid() + 1),
            types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o720, st_uid=os.geteuid()),
            types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o702, st_uid=os.geteuid()),
        )
        for value in metadata:
            with self.subTest(metadata=value):
                self.assert_validation_error(
                    helper.ERR_DIRECTORY,
                    helper._validate_directory_metadata,
                    value,
                )

    def test_opened_root_must_still_match_cwd_inode(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            mismatched = types.SimpleNamespace(st_dev=-1, st_ino=-1)
            with mock.patch.object(helper.os, "stat", return_value=mismatched):
                self.assert_validation_error(
                    helper.ERR_DIRECTORY,
                    helper._open_root_directory,
                    Path(temporary),
                )


class ResidueAndStagingTests(ValidationTestCase):
    def test_residue_scan_entry_boundaries(self) -> None:
        for count in (0, 1, 4_095, 4_096):
            entries = [FakeEntry(f"entry-{index}") for index in range(count)]
            with (
                self.subTest(count=count),
                mock.patch.object(
                    helper.os, "scandir", return_value=FakeScandir(entries)
                ),
            ):
                helper.scan_staging_residue(10)

        entries = [FakeEntry(f"entry-{index}") for index in range(4_097)]
        with mock.patch.object(helper.os, "scandir", return_value=FakeScandir(entries)):
            self.assert_validation_error(
                helper.ERR_RESIDUE_SCAN_LIMIT, helper.scan_staging_residue, 10
            )

    def test_reserved_entry_types_are_all_rejected_without_deletion(self) -> None:
        kinds = ("regular", "directory", "symlink", "dangling")
        for kind in kinds:
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as temporary:
                directory = Path(temporary)
                reserved = directory / f"{helper.STAGING_PREFIX}{'a' * 32}"
                if kind == "regular":
                    reserved.write_bytes(b"fixture")
                elif kind == "directory":
                    reserved.mkdir()
                elif kind == "symlink":
                    target = directory / "target"
                    target.write_bytes(b"fixture")
                    reserved.symlink_to(target.name)
                else:
                    reserved.symlink_to("missing")
                directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    self.assert_validation_error(
                        helper.ERR_STAGING_RESIDUE,
                        helper.scan_staging_residue,
                        directory_fd,
                    )
                    self.assertTrue(reserved.exists() or reserved.is_symlink())
                finally:
                    os.close(directory_fd)

    def test_scan_stat_error_is_fixed_failure(self) -> None:
        entries = [FakeEntry("entry", OSError("synthetic"))]
        with mock.patch.object(helper.os, "scandir", return_value=FakeScandir(entries)):
            self.assert_validation_error(
                helper.ERR_RESIDUE_SCAN_FAILED, helper.scan_staging_residue, 10
            )

    def test_staging_retries_at_most_eight_eexist_collisions(self) -> None:
        collision = OSError(errno.EEXIST, "synthetic")
        for collisions in range(1, 8):
            open_mock = mock.Mock(side_effect=[collision] * collisions + [42])
            with (
                self.subTest(collisions=collisions),
                mock.patch.object(helper.os, "open", open_mock),
            ):
                staging = helper.create_staging_file(9, lambda _: "a" * 32)
                self.assertEqual(staging.fd, 42)
                self.assertEqual(open_mock.call_count, collisions + 1)

        open_mock = mock.Mock(side_effect=[collision] * 7 + [42])
        with mock.patch.object(helper.os, "open", open_mock):
            self.assertEqual(helper.create_staging_file(9, lambda _: "a" * 32).fd, 42)
            self.assertEqual(open_mock.call_count, 8)

        open_mock = mock.Mock(side_effect=[collision] * 9)
        with mock.patch.object(helper.os, "open", open_mock):
            self.assert_validation_error(
                helper.ERR_STAGING_COLLISION_LIMIT,
                helper.create_staging_file,
                9,
                lambda _: "a" * 32,
            )
            self.assertEqual(open_mock.call_count, 8)

    def test_staging_flags_mode_and_dir_fd(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            directory_fd = os.open(temporary, os.O_RDONLY | os.O_DIRECTORY)
            staging = helper.create_staging_file(directory_fd, lambda _: "b" * 32)
            try:
                metadata = os.fstat(staging.fd)
                self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o600)
                self.assertTrue(stat.S_ISREG(metadata.st_mode))
            finally:
                os.close(staging.fd)
                os.unlink(staging.name, dir_fd=directory_fd)
                os.close(directory_fd)

        open_mock = mock.Mock(return_value=42)
        with mock.patch.object(helper.os, "open", open_mock):
            helper.create_staging_file(9, lambda _: "c" * 32)
        name, flags, mode = open_mock.call_args.args
        self.assertTrue(name.startswith(helper.STAGING_PREFIX))
        self.assertEqual(mode, 0o600)
        self.assertEqual(open_mock.call_args.kwargs, {"dir_fd": 9})
        for flag in (
            os.O_CREAT,
            os.O_EXCL,
            os.O_WRONLY,
            os.O_NOFOLLOW,
            os.O_CLOEXEC,
        ):
            self.assertEqual(flags & flag, flag)


class AtomicPublishTests(ValidationTestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.directory = Path(self.temporary.name)
        self.directory_fd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY)

    def tearDown(self) -> None:
        os.close(self.directory_fd)
        self.temporary.cleanup()

    def _staging_names(self) -> list[str]:
        return [
            entry.name
            for entry in self.directory.iterdir()
            if entry.name.startswith(helper.STAGING_PREFIX)
        ]

    def test_write_all_handles_short_writes_and_zero_progress(self) -> None:
        with mock.patch.object(helper.os, "write", side_effect=(2, 3)) as write_mock:
            helper.write_all(10, b"abcde")
        self.assertEqual(write_mock.call_count, 2)

        with mock.patch.object(helper.os, "write", return_value=0):
            self.assert_validation_error(helper.ERR_WRITE, helper.write_all, 10, b"x")

    def test_complete_publish_is_0600_exact_and_removes_staging(self) -> None:
        result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result, helper.PublishResult(helper.PublishState.COMPLETE))
        final = self.directory / "final.md"
        self.assertEqual(final.read_bytes(), b"content")
        self.assertEqual(stat.S_IMODE(final.stat().st_mode), 0o600)
        self.assertEqual(self._staging_names(), [])

    def test_empty_content_is_published_without_placeholder(self) -> None:
        result = helper.atomic_publish(self.directory_fd, "empty.txt", b"")
        self.assertEqual(result, helper.PublishResult(helper.PublishState.COMPLETE))
        self.assertEqual((self.directory / "empty.txt").read_bytes(), b"")
        self.assertEqual(self._staging_names(), [])

    def test_existing_target_is_unchanged(self) -> None:
        final = self.directory / "final.md"
        final.write_bytes(b"existing")
        result = helper.atomic_publish(self.directory_fd, "final.md", b"new")
        self.assertEqual(result.state, helper.PublishState.FAILED)
        self.assertEqual(result.error_code, helper.ERR_TARGET_EXISTS)
        self.assertEqual(final.read_bytes(), b"existing")
        self.assertEqual(self._staging_names(), [])

    def test_write_enospc_fsync_close_and_link_failures_cleanup(self) -> None:
        original_close = os.close
        original_fsync = os.fsync

        def close_then_fail(file_fd: int) -> None:
            original_close(file_fd)
            raise OSError(errno.EIO, "synthetic")

        def fail_file_fsync(file_fd: int) -> None:
            if file_fd != self.directory_fd:
                raise OSError(errno.EIO, "synthetic")
            original_fsync(file_fd)

        failures = (
            (
                "write",
                mock.patch.object(
                    helper.os,
                    "write",
                    side_effect=OSError(errno.ENOSPC, "synthetic"),
                ),
                helper.ERR_WRITE,
            ),
            (
                "fsync",
                mock.patch.object(helper.os, "fsync", side_effect=fail_file_fsync),
                helper.ERR_FILE_FSYNC,
            ),
            (
                "close",
                mock.patch.object(helper.os, "close", side_effect=close_then_fail),
                helper.ERR_FILE_CLOSE,
            ),
            (
                "link",
                mock.patch.object(
                    helper.os,
                    "link",
                    side_effect=OSError(errno.EIO, "synthetic"),
                ),
                helper.ERR_LINK,
            ),
        )
        for name, patcher, expected_code in failures:
            with self.subTest(name=name), patcher:
                result = helper.atomic_publish(
                    self.directory_fd, f"{name}.md", b"content"
                )
            self.assertEqual(result.state, helper.PublishState.FAILED)
            self.assertEqual(result.error_code, expected_code)
            self.assertEqual(self._staging_names(), [])

    def test_link_eexist_cleans_only_current_staging(self) -> None:
        def create_racing_target(category_fd: int, filename: str) -> None:
            del category_fd
            (self.directory / filename).write_bytes(b"racing-existing")

        with mock.patch.object(
            helper, "ensure_target_absent", side_effect=create_racing_target
        ):
            result = helper.atomic_publish(self.directory_fd, "race.md", b"content")
        self.assertEqual(result.state, helper.PublishState.FAILED)
        self.assertEqual(result.error_code, helper.ERR_TARGET_EXISTS)
        self.assertEqual((self.directory / "race.md").read_bytes(), b"racing-existing")
        self.assertEqual(self._staging_names(), [])

    def test_link_pre_failure_cleanup_error_is_failed_with_residue(self) -> None:
        with (
            mock.patch.object(
                helper.os, "link", side_effect=OSError(errno.EIO, "synthetic")
            ),
            mock.patch.object(
                helper.os, "unlink", side_effect=OSError(errno.EIO, "synthetic")
            ),
        ):
            result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result.state, helper.PublishState.FAILED_WITH_RESIDUE)
        self.assertEqual(result.error_code, helper.ERR_STAGING_UNLINK)
        self.assertEqual(len(self._staging_names()), 1)

    def test_link_pre_cleanup_fsync_failure_is_failed_with_residue(self) -> None:
        original_fsync = os.fsync

        def fail_cleanup_fsync(file_fd: int) -> None:
            if file_fd == self.directory_fd:
                raise OSError(errno.EIO, "synthetic")
            original_fsync(file_fd)

        with (
            mock.patch.object(
                helper.os, "link", side_effect=OSError(errno.EIO, "synthetic")
            ),
            mock.patch.object(helper.os, "fsync", side_effect=fail_cleanup_fsync),
        ):
            result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result.state, helper.PublishState.FAILED_WITH_RESIDUE)
        self.assertEqual(result.error_code, helper.ERR_CLEANUP_FSYNC)
        self.assertEqual(self._staging_names(), [])

    def test_post_link_diagnostic_failure_is_indeterminate_without_cleanup(
        self,
    ) -> None:
        with mock.patch.object(
            helper,
            "_post_link_diagnostic",
            side_effect=helper.ValidationError(helper.ERR_POST_LINK_DIAGNOSTIC),
        ):
            result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result.state, helper.PublishState.INDETERMINATE)
        self.assertEqual(result.error_code, helper.ERR_POST_LINK_DIAGNOSTIC)
        self.assertTrue((self.directory / "final.md").exists())
        self.assertEqual(len(self._staging_names()), 1)

    def test_diagnostic_rejects_type_mode_device_inode_and_stat_error(self) -> None:
        regular = stat.S_IFREG | 0o600
        valid = types.SimpleNamespace(st_mode=regular, st_dev=1, st_ino=2)
        invalid_finals = (
            types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o600, st_dev=1, st_ino=2),
            types.SimpleNamespace(st_mode=stat.S_IFREG | 0o644, st_dev=1, st_ino=2),
            types.SimpleNamespace(st_mode=regular, st_dev=9, st_ino=2),
            types.SimpleNamespace(st_mode=regular, st_dev=1, st_ino=9),
        )
        for final in invalid_finals:
            with (
                self.subTest(final=final),
                mock.patch.object(helper.os, "stat", side_effect=(valid, final)),
            ):
                self.assert_validation_error(
                    helper.ERR_POST_LINK_DIAGNOSTIC,
                    helper._post_link_diagnostic,
                    self.directory_fd,
                    "staging",
                    "final.md",
                )
        with mock.patch.object(helper.os, "stat", side_effect=OSError("synthetic")):
            self.assert_validation_error(
                helper.ERR_POST_LINK_DIAGNOSTIC,
                helper._post_link_diagnostic,
                self.directory_fd,
                "staging",
                "final.md",
            )

    def test_publish_directory_fsync_failure_is_indeterminate(self) -> None:
        original_fsync = os.fsync

        def fail_directory_fsync(file_fd: int) -> None:
            if file_fd == self.directory_fd:
                raise OSError(errno.EIO, "synthetic")
            original_fsync(file_fd)

        with mock.patch.object(helper.os, "fsync", side_effect=fail_directory_fsync):
            result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result.state, helper.PublishState.INDETERMINATE)
        self.assertEqual(result.error_code, helper.ERR_PUBLISH_FSYNC)
        self.assertTrue((self.directory / "final.md").exists())
        self.assertEqual(len(self._staging_names()), 1)

    def test_unlink_failure_is_published_with_residue(self) -> None:
        with mock.patch.object(
            helper.os, "unlink", side_effect=OSError(errno.EIO, "synthetic")
        ):
            result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result.state, helper.PublishState.PUBLISHED_WITH_RESIDUE)
        self.assertEqual(result.error_code, helper.ERR_STAGING_UNLINK)
        self.assertTrue((self.directory / "final.md").exists())
        self.assertEqual(len(self._staging_names()), 1)

    def test_cleanup_directory_fsync_failure_is_published_with_residue(self) -> None:
        original_fsync = os.fsync
        directory_fsync_count = 0

        def fail_second_directory_fsync(file_fd: int) -> None:
            nonlocal directory_fsync_count
            if file_fd == self.directory_fd:
                directory_fsync_count += 1
                if directory_fsync_count == 2:
                    raise OSError(errno.EIO, "synthetic")
            original_fsync(file_fd)

        with mock.patch.object(
            helper.os, "fsync", side_effect=fail_second_directory_fsync
        ):
            result = helper.atomic_publish(self.directory_fd, "final.md", b"content")
        self.assertEqual(result.state, helper.PublishState.PUBLISHED_WITH_RESIDUE)
        self.assertEqual(result.error_code, helper.ERR_CLEANUP_FSYNC)
        self.assertTrue((self.directory / "final.md").exists())
        self.assertEqual(self._staging_names(), [])


class ConcurrencyAndKillTests(unittest.TestCase):
    def test_same_name_concurrency_never_overwrites_or_deletes_other_staging(
        self,
    ) -> None:
        context = multiprocessing.get_context("fork")
        with tempfile.TemporaryDirectory() as temporary:
            start_event = context.Event()
            result_queue = context.Queue()
            processes = [
                context.Process(
                    target=atomic_worker,
                    args=(temporary, "final.md", content, start_event, result_queue),
                )
                for content in (b"first", b"second")
            ]
            for process in processes:
                process.start()
            start_event.set()
            for process in processes:
                process.join(5)
                self.assertEqual(process.exitcode, 0)
            results = [result_queue.get(timeout=1) for _ in processes]
            self.assertIn((helper.PublishState.COMPLETE.value, None), results)
            self.assertIn(
                (Path(temporary) / "final.md").read_bytes(), (b"first", b"second")
            )
            self.assertFalse(
                any(
                    entry.name.startswith(helper.STAGING_PREFIX)
                    for entry in Path(temporary).iterdir()
                )
            )

    def test_different_name_concurrency_preserves_content_and_fails_closed(
        self,
    ) -> None:
        context = multiprocessing.get_context("fork")
        cases = (
            ("first.md", b"first-content"),
            ("second.md", b"second-content"),
        )
        allowed_failures = {
            helper.ERR_STAGING_RESIDUE,
            helper.ERR_RESIDUE_SCAN_FAILED,
        }
        with tempfile.TemporaryDirectory() as temporary:
            start_event = context.Event()
            result_queues = [context.Queue() for _ in cases]
            processes = [
                context.Process(
                    target=atomic_worker,
                    args=(
                        temporary,
                        filename,
                        content,
                        start_event,
                        result_queue,
                    ),
                )
                for (filename, content), result_queue in zip(cases, result_queues)
            ]
            for process in processes:
                process.start()
            start_event.set()
            for process in processes:
                process.join(5)
                self.assertEqual(process.exitcode, 0)

            for (filename, content), result_queue in zip(cases, result_queues):
                state, error_code = result_queue.get(timeout=1)
                final_path = Path(temporary) / filename
                if state == helper.PublishState.COMPLETE.value:
                    self.assertIsNone(error_code)
                    self.assertEqual(final_path.read_bytes(), content)
                else:
                    self.assertEqual(state, helper.PublishState.FAILED.value)
                    self.assertIn(error_code, allowed_failures)
                    self.assertFalse(final_path.exists())

            entries = list(Path(temporary).iterdir())
            self.assertFalse(
                any(entry.name.startswith(helper.STAGING_PREFIX) for entry in entries)
            )
            expected_content = dict(cases)
            for entry in entries:
                self.assertIn(entry.name, expected_content)
                self.assertEqual(entry.read_bytes(), expected_content[entry.name])

    def test_process_kill_leaves_residue_that_next_scan_rejects(self) -> None:
        context = multiprocessing.get_context("fork")
        with tempfile.TemporaryDirectory() as temporary:
            parent_connection, child_connection = context.Pipe(duplex=False)
            process = context.Process(
                target=staging_kill_worker,
                args=(temporary, child_connection),
            )
            process.start()
            child_connection.close()
            staging_name = parent_connection.recv()
            os.kill(process.pid, signal.SIGKILL)
            process.join(5)
            self.assertEqual(process.exitcode, -signal.SIGKILL)
            self.assertTrue((Path(temporary) / staging_name).exists())
            directory_fd = os.open(temporary, os.O_RDONLY | os.O_DIRECTORY)
            try:
                with self.assertRaises(helper.ValidationError) as caught:
                    helper.scan_staging_residue(directory_fd)
                self.assertEqual(caught.exception.code, helper.ERR_STAGING_RESIDUE)
                self.assertTrue((Path(temporary) / staging_name).exists())
            finally:
                os.close(directory_fd)


if __name__ == "__main__":
    unittest.main()
