---
name: save-local-artifact
description: ユーザーが明示したテキスト成果物を、trusted preflightと毎回の承認を経て.ai-workの許可categoryへ新規保存する。
disable-model-invocation: true
---

# 共用ローカル成果物の限定保存

このSkillはファイル作成という副作用を伴うため、Claudeから自動起動せず、ユーザーが`/save-local-artifact`を明示実行した場合だけ開始します。

Skillの起動自体はtool permissionの事前承認ではありません。このSkillには`allowed-tools`を設定せず、preflightとsaveは毎回通常のBash toolとしてPreToolUse Hookの検査と人間の承認を受けます。Skillから呼ばれたというprovenanceを安全境界にせず、Hook、人間承認、helper自身のvalidationとfilesystem安全境界を技術的な防御とします。

## 必須入力

`$ARGUMENTS`から次を別々に確認します。

- 保存目的
- category: `reports`、`handoffs`、`scratch`のいずれか
- filename: pathを含まないbasename
- 保存内容: 人間が保存対象として意図した本文全文

不足や曖昧さがあれば推測・補完せず、ユーザーへ確認してhelperを実行しません。category、filename、本文を勝手に選びません。`$ARGUMENTS`をshell commandへ直接連結しません。

任意pathは受け付けません。`.ai-work/reports/a.md`、`/tmp/a.md`、`docs/a.md`などが指定された場合も、そのpathをhelperへ渡さず、categoryとfilenameを別々に確認します。

## 保存前の安全確認

`docs/AI_LOCAL_ARTIFACTS.md`を正本とし、秘密情報、credential、token、API key、private key、個人情報、外部取得内容のraw responseは保存しません。秘密情報を自動検出できるとは扱いません。

外部情報は、人間確認済みの要約、必要最小限の引用、出典、確認日、未確認事項に限定します。既存の`.ai-work/`成果物は読みません。

このSkillは新規作成専用です。上書き、追記、削除、rename、move、directory作成は行いません。

## Phase 1: trusted preflight

1. 保存目的、category、filename、保存内容をユーザーの指定どおり分離します。
2. 保存内容のUTF-8 bytesから、unpadded base64url payloadを生成します。payloadをshell変数、temporary file、heredoc、pipe、redirectへ渡しません。canonicality、文字仕様、byte上限の最終判定はhelperへ委ねます。
3. 実値を埋めた次のcanonical commandを、quoteやoption順の変更がない1行の通常Bash tool呼び出しとして実行候補にします。placeholderを含む例示command自体は実行しません。

```text
python3 .claude/skills/save-local-artifact/scripts/save_local_artifact.py preflight --category <category> --filename <filename> --content-base64url=<payload>
```

4. Hook検査とその回の人間承認を経て、helperのpreflightを実行します。
5. Skill自身のpreviewやモデルによる要約・転記ではなく、trusted helperの実際のtool出力そのものを、人間が直接確認できる形で提示します。
6. 人間がtool出力のcategory、filename、`normalized-byte-count`、`confirmation-digest` 64文字全部、およびfixed framing内のnormalized content全文を`----- END NORMALIZED CONTENT -----`まで確認できた場合だけ次へ進めます。truncate、省略、欠落、途中終了、折り畳み等がある場合や、UI・実行環境上でtool出力そのものを直接確認できない場合はsaveしません。
7. ここで必ず停止し、表示された正規化後の実保存内容とdigestで保存してよいか、会話上の明示確認を求めます。preflightと同じturnで自動的にsaveへ進みません。

fixed framingは本文境界を見やすくする表示であり、本文やdigestの一部ではありません。`normalized-byte-count`は本文確認の補助情報であり、本文全文の直接確認を代替しません。

## Phase 2: save

ユーザーがsave直前の最新trusted preflight結果を確認し、明確に保存を承認した場合だけ進みます。同一sessionで複数回preflightした場合は最新の結果だけを有効とし、どの結果が対象か不明になった場合は以前のdigestを再利用せずPhase 1からやり直します。

preflight後にcategory、filename、contentのいずれかが変わった場合は、以前のpayloadとdigestを破棄し、Phase 1からやり直します。confirmation digestが保存先と本文をbindするため、変更前のpreflightを流用しません。

確認済みpreflightと同じpayload、およびhelperが表示したconfirmation digestを使います。本文を整形、要約、修正せず、末尾LFを追加・削除しません。実値を埋めた次のcanonical commandを、1行の通常Bash tool呼び出しとして実行候補にします。

```text
python3 .claude/skills/save-local-artifact/scripts/save_local_artifact.py save --category <category> --filename <filename> --confirmation-digest <64-lower-hex> --content-base64url=<payload>
```

saveも改めてHook検査とその回の人間承認を受けます。

save承認画面では、固定helper path、modeが`save`であること、category、filename、confirmation digest 64文字全部、canonical command shapeを確認します。digestは先頭・末尾・一部だけで照合しません。base64url payload全文の意味内容を目視decodeすることは要件とせず、本文はtrusted preflightのtool出力、preflightとsaveの同一性はdigest bindingとhelperによる再計算で確認します。

## 結果と失敗時の扱い

- preflightのvalidation failureでは、固定errorを報告して停止します。保存済みとは報告しません。
- `FAILED`では、固定status/errorを報告して停止します。保存済みとは報告しません。
- `FAILED_WITH_RESIDUE`では、自動retry・自動cleanupをせず、人間の確認が必要と報告します。
- `INDETERMINATE`では、成功とも失敗とも断定せず、自動retry・自動cleanupをせず、人間向け復旧確認が必要と報告します。
- `PUBLISHED_WITH_RESIDUE`では、完全成功として扱わず、statusをそのまま報告して人間の確認を求めます。
- helperが`status: COMPLETE`を返した場合だけ正常保存完了とし、helper出力の実際の保存path、category、filename、`saved-byte-count`、`confirmation-digest`を報告します。`saved-byte-count`とdigestはpreflight結果との事後照合にも使いますが、save前のdigest 64文字比較を代替しません。本文やpayloadは再出力しません。
- ユーザーがsaveを承認しなかった場合やsaveが失敗した場合も、保存済みとは報告しません。

helperが失敗しても、別形の`python3`、Edit、Write、NotebookEdit、redirect、`tee`、heredoc、`touch`、別pathなどへfallbackしません。自動retry、既存residueの削除、別の保存手段は使用せず、固定status/errorを人間へ報告して停止します。

既存の`pre-implementation-review`と`pr-diff-review`からこのSkillやhelperを呼びません。レビュー成果物は従来どおりチャットへ直接返します。
