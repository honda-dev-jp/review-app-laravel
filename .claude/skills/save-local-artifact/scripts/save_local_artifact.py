#!/usr/bin/env python3
"""共用ローカル成果物helperの入力をclosed worldで検証する。"""

from __future__ import annotations

import base64
import binascii
import errno
import hashlib
import os
import re
import secrets
import stat
import sys
from enum import Enum
from pathlib import Path
from typing import Callable, NamedTuple, Sequence


CATEGORIES = frozenset({"reports", "handoffs", "scratch"})
# helper単体の理論値ではなく、Claude Code E2Eで内容完全性を実証できた
# encoded上限を主境界とする。rawはそこから導出し、引上げ時はE2Eを再確認する。
MAX_ENCODED_BYTES = 2_048
MAX_RAW_BYTES = 1_536
MAX_NORMALIZED_BYTES = 1_536
CONFIRMATION_DIGEST_PREFIX = b"review-app-laravel/save-local-artifact/v1\x00"
BEGIN_NORMALIZED_CONTENT = b"----- BEGIN NORMALIZED CONTENT -----\n"
END_NORMALIZED_CONTENT = b"----- END NORMALIZED CONTENT -----\n"
STAGING_PREFIX = ".__claude_save_staging_"
MAX_RESIDUE_ENTRIES = 4_096
MAX_STAGING_ATTEMPTS = 8

FILENAME_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_-]{0,62}\.(?:md|txt)\Z", re.ASCII)
BASE64URL_RE = re.compile(r"[A-Za-z0-9_-]*\Z", re.ASCII)
CONFIRMATION_DIGEST_RE = re.compile(r"[0-9a-f]{64}\Z", re.ASCII)
STAGING_TOKEN_RE = re.compile(r"[0-9a-f]{32}\Z", re.ASCII)
WINDOWS_MOUNT_RE = re.compile(r"/mnt/[A-Za-z](?:/|\Z)", re.ASCII)

ERR_ARGUMENT_SCHEMA = "ERR_ARGUMENT_SCHEMA"
ERR_CATEGORY = "ERR_CATEGORY"
ERR_FILENAME = "ERR_FILENAME"
ERR_CONTENT_ENCODED_SIZE = "ERR_CONTENT_ENCODED_SIZE"
ERR_CONTENT_BASE64URL = "ERR_CONTENT_BASE64URL"
ERR_CONTENT_RAW_SIZE = "ERR_CONTENT_RAW_SIZE"
ERR_CONTENT_UTF8 = "ERR_CONTENT_UTF8"
ERR_CONTENT_CHARACTER = "ERR_CONTENT_CHARACTER"
ERR_CONTENT_NORMALIZED_SIZE = "ERR_CONTENT_NORMALIZED_SIZE"
ERR_CONFIRMATION_DIGEST = "ERR_CONFIRMATION_DIGEST"
ERR_CONFIRMATION_MISMATCH = "ERR_CONFIRMATION_MISMATCH"
ERR_REPOSITORY_ROOT = "ERR_REPOSITORY_ROOT"
ERR_RUNTIME_UNSUPPORTED = "ERR_RUNTIME_UNSUPPORTED"
ERR_API_CAPABILITY = "ERR_API_CAPABILITY"
ERR_DIRECTORY = "ERR_DIRECTORY"
ERR_TARGET_EXISTS = "ERR_TARGET_EXISTS"
ERR_RESIDUE_SCAN_LIMIT = "ERR_RESIDUE_SCAN_LIMIT"
ERR_STAGING_RESIDUE = "ERR_STAGING_RESIDUE"
ERR_RESIDUE_SCAN_FAILED = "ERR_RESIDUE_SCAN_FAILED"
ERR_STAGING_COLLISION_LIMIT = "ERR_STAGING_COLLISION_LIMIT"
ERR_STAGING_CREATE = "ERR_STAGING_CREATE"
ERR_WRITE = "ERR_WRITE"
ERR_FILE_FSYNC = "ERR_FILE_FSYNC"
ERR_FILE_CLOSE = "ERR_FILE_CLOSE"
ERR_LINK = "ERR_LINK"
ERR_POST_LINK_DIAGNOSTIC = "ERR_POST_LINK_DIAGNOSTIC"
ERR_PUBLISH_FSYNC = "ERR_PUBLISH_FSYNC"
ERR_STAGING_UNLINK = "ERR_STAGING_UNLINK"
ERR_CLEANUP_FSYNC = "ERR_CLEANUP_FSYNC"
ERR_INTERNAL = "ERR_INTERNAL"

SAFE_REASONS = {
    ERR_REPOSITORY_ROOT: "Repository root validation failed",
    ERR_RUNTIME_UNSUPPORTED: "Runtime or filesystem is unsupported",
    ERR_API_CAPABILITY: "Required filesystem capability is unavailable",
    ERR_DIRECTORY: "Artifact directory validation failed",
    ERR_TARGET_EXISTS: "Target already exists",
    ERR_RESIDUE_SCAN_LIMIT: "Residue scan entry limit exceeded",
    ERR_STAGING_RESIDUE: "Staging residue requires human inspection",
    ERR_RESIDUE_SCAN_FAILED: "Residue scan failed",
    ERR_STAGING_COLLISION_LIMIT: "Staging collision limit reached",
    ERR_STAGING_CREATE: "Staging creation failed",
    ERR_WRITE: "Staging write failed",
    ERR_FILE_FSYNC: "Staging durability failed",
    ERR_FILE_CLOSE: "Staging close failed",
    ERR_LINK: "Atomic publish failed",
    ERR_POST_LINK_DIAGNOSTIC: "Published names require human inspection",
    ERR_PUBLISH_FSYNC: "Publish durability is indeterminate",
    ERR_STAGING_UNLINK: "Published artifact has staging residue",
    ERR_CLEANUP_FSYNC: "Staging cleanup durability is uncertain",
    ERR_INTERNAL: "Internal helper failure",
}


class ValidationError(Exception):
    """入力内容を含めず、固定codeだけを返すvalidation失敗。"""

    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


class ParsedCommand(NamedTuple):
    mode: str
    category: str
    filename: str
    confirmation_digest: str | None
    content_base64url: str


class PublishState(str, Enum):
    PRE_PUBLISH = "PRE_PUBLISH"
    FAILED = "FAILED"
    FAILED_WITH_RESIDUE = "FAILED_WITH_RESIDUE"
    INDETERMINATE = "INDETERMINATE"
    PUBLISHED_DURABLE = "PUBLISHED_DURABLE"
    PUBLISHED_WITH_RESIDUE = "PUBLISHED_WITH_RESIDUE"
    COMPLETE = "COMPLETE"


class PublishResult(NamedTuple):
    state: PublishState
    error_code: str | None = None


class StagingFile(NamedTuple):
    name: str
    fd: int


def validate_category(category: str) -> str:
    if not isinstance(category, str) or category not in CATEGORIES:
        raise ValidationError(ERR_CATEGORY)
    return category


def validate_filename(filename: str) -> str:
    if not isinstance(filename, str) or not FILENAME_RE.fullmatch(filename):
        raise ValidationError(ERR_FILENAME)
    return filename


def _validate_encoded_size(payload: str) -> None:
    if len(payload) > MAX_ENCODED_BYTES:
        raise ValidationError(ERR_CONTENT_ENCODED_SIZE)


def _validate_raw_size(raw: bytes) -> None:
    if len(raw) > MAX_RAW_BYTES:
        raise ValidationError(ERR_CONTENT_RAW_SIZE)


def _validate_normalized_size(normalized: bytes) -> None:
    if len(normalized) > MAX_NORMALIZED_BYTES:
        raise ValidationError(ERR_CONTENT_NORMALIZED_SIZE)


def decode_canonical_base64url(payload: str) -> bytes:
    """Canonicalなunpadded base64urlだけをdecodeする。"""
    if not isinstance(payload, str):
        raise ValidationError(ERR_CONTENT_BASE64URL)
    _validate_encoded_size(payload)
    if not BASE64URL_RE.fullmatch(payload):
        raise ValidationError(ERR_CONTENT_BASE64URL)

    padding = "=" * (-len(payload) % 4)
    try:
        raw = base64.b64decode(
            (payload + padding).encode("ascii"), altchars=b"-_", validate=True
        )
    except (UnicodeEncodeError, binascii.Error, ValueError):
        raise ValidationError(ERR_CONTENT_BASE64URL) from None

    # 同じbyte列の別表現がHook/helper間のcanonical性を弱めないよう、再encodeと
    # 完全一致を要求し、non-zero pad bits等をfail-closedで拒否する。
    canonical = base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")
    if canonical != payload:
        raise ValidationError(ERR_CONTENT_BASE64URL)
    _validate_raw_size(raw)
    return raw


def _character_is_forbidden(character: str) -> bool:
    code_point = ord(character)
    return (
        (code_point <= 0x1F and code_point not in {0x09, 0x0A})
        or code_point == 0x7F
        or 0x80 <= code_point <= 0x9F
        or code_point in {0xFEFF, 0x2028, 0x2029}
    )


def normalize_and_validate_content(raw: bytes) -> bytes:
    """UTF-8本文を改行以外は変換せず、保存用byte列へ正規化する。"""
    if not isinstance(raw, bytes):
        raise ValidationError(ERR_CONTENT_UTF8)
    # 改行正規化による縮小でtransportのraw byte上限を迂回させないよう、
    # raw sizeは正規化前に独立して検証する。
    _validate_raw_size(raw)
    try:
        text = raw.decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        raise ValidationError(ERR_CONTENT_UTF8) from None

    normalized_text = text.replace("\r\n", "\n").replace("\r", "\n")
    if any(_character_is_forbidden(character) for character in normalized_text):
        raise ValidationError(ERR_CONTENT_CHARACTER)

    # Unicode normalizationは行わない。UTF-8への再encodeは改行正規化後の
    # code point列をそのまま保存用byte列に戻すためだけに使用する。
    normalized = normalized_text.encode("utf-8")
    _validate_normalized_size(normalized)
    return normalized


def decode_normalize_validate_content(payload: str) -> bytes:
    """preflightとsaveが共有する本文validation pipeline。"""
    return normalize_and_validate_content(decode_canonical_base64url(payload))


def compute_confirmation_digest(
    category: str, filename: str, normalized_content: bytes
) -> str:
    """検証済み保存先と正規化済み本文を確認用digestへbindする。"""
    category_ascii = validate_category(category).encode("ascii")
    filename_ascii = validate_filename(filename).encode("ascii")

    # 固定versionとNUL separatorでdomain separationし、保存先と本文の境界を
    # 曖昧な単純連結にせず1つの確認値へbindする。
    digest_input = (
        CONFIRMATION_DIGEST_PREFIX
        + category_ascii
        + b"\x00"
        + filename_ascii
        + b"\x00"
        + normalized_content
    )
    return hashlib.sha256(digest_input).hexdigest()


def build_preflight_output(
    category: str, filename: str, normalized_content: bytes
) -> bytes:
    """trusted preflightの固定形式stdoutを組み立てる。"""
    confirmation_digest = compute_confirmation_digest(
        category, filename, normalized_content
    )
    fields = (
        f"category: {category}\n"
        f"filename: {filename}\n"
        f"normalized-byte-count: {len(normalized_content)}\n"
        f"confirmation-digest: {confirmation_digest}\n"
    ).encode("ascii")

    # framingは本文境界を目視しやすくする表示専用で、本文やdigestへ混入させない。
    # delimiterを含む本文もbyte数とdigestで同一性を確認する。
    return (
        fields + BEGIN_NORMALIZED_CONTENT + normalized_content + END_NORMALIZED_CONTENT
    )


def validate_confirmation_digest(digest: str) -> str:
    if not isinstance(digest, str) or not CONFIRMATION_DIGEST_RE.fullmatch(digest):
        raise ValidationError(ERR_CONFIRMATION_DIGEST)
    return digest


def derive_repository_root(
    helper_file: str | os.PathLike[str] | None = None,
    cwd: str | os.PathLike[str] | None = None,
) -> Path:
    """helperの固定配置とcwd inodeからrepository rootを確定する。"""
    helper_path = Path(__file__ if helper_file is None else helper_file)
    if not helper_path.is_absolute():
        helper_path = Path(os.path.abspath(helper_path))

    scripts = helper_path.parent
    skill = scripts.parent
    skills = skill.parent
    claude = skills.parent
    root = claude.parent
    if (
        scripts.name != "scripts"
        or skill.name != "save-local-artifact"
        or skills.name != "skills"
        or claude.name != ".claude"
    ):
        raise ValidationError(ERR_REPOSITORY_ROOT)

    # caller指定rootやcwd文字列だけでは保存先が曖昧になるため、helper自身の
    # 固定配置をlstatし、symlinkを含まないcomponentからrootを導く。
    expected = (
        (root, stat.S_ISDIR),
        (claude, stat.S_ISDIR),
        (skills, stat.S_ISDIR),
        (skill, stat.S_ISDIR),
        (scripts, stat.S_ISDIR),
        (helper_path, stat.S_ISREG),
    )
    try:
        for path, type_check in expected:
            metadata = path.lstat()
            if stat.S_ISLNK(metadata.st_mode) or not type_check(metadata.st_mode):
                raise ValidationError(ERR_REPOSITORY_ROOT)
        root_stat = root.stat()
        cwd_stat = Path(os.getcwd() if cwd is None else cwd).stat()
    except ValidationError:
        raise
    except OSError:
        raise ValidationError(ERR_REPOSITORY_ROOT) from None
    if (root_stat.st_dev, root_stat.st_ino) != (cwd_stat.st_dev, cwd_stat.st_ino):
        raise ValidationError(ERR_REPOSITORY_ROOT)
    return root


def _has_required_api_capabilities() -> bool:
    constants = ("O_DIRECTORY", "O_NOFOLLOW", "O_CLOEXEC")
    functions = ("open", "link", "unlink", "stat", "scandir", "fsync")
    if not all(hasattr(os, name) for name in (*constants, *functions)):
        return False
    if not all(
        function in os.supports_dir_fd
        for function in (os.open, os.link, os.unlink, os.stat)
    ):
        return False
    return (
        os.scandir in os.supports_fd
        and os.link in os.supports_follow_symlinks
        and os.stat in os.supports_follow_symlinks
    )


def _read_osrelease() -> bytes:
    return Path("/proc/sys/kernel/osrelease").read_bytes()


def validate_runtime(
    root: Path,
    *,
    platform_name: str | None = None,
    osrelease_reader: Callable[[], bytes] | None = None,
    capability_checker: Callable[[], bool] | None = None,
    realpath: Callable[[os.PathLike[str]], str] | None = None,
) -> None:
    """対象WSL/Linux filesystem候補だけをfail-closedで受理する。"""
    if (sys.platform if platform_name is None else platform_name) != "linux":
        raise ValidationError(ERR_RUNTIME_UNSUPPORTED)
    try:
        release = (_read_osrelease if osrelease_reader is None else osrelease_reader)()
    except Exception:
        raise ValidationError(ERR_RUNTIME_UNSUPPORTED) from None
    if not isinstance(release, bytes):
        raise ValidationError(ERR_RUNTIME_UNSUPPORTED)
    lowered = release.lower()
    if b"microsoft" not in lowered and b"wsl" not in lowered:
        raise ValidationError(ERR_RUNTIME_UNSUPPORTED)

    try:
        physical_root = (os.path.realpath if realpath is None else realpath)(root)
    except Exception:
        raise ValidationError(ERR_RUNTIME_UNSUPPORTED) from None
    if not isinstance(physical_root, str) or WINDOWS_MOUNT_RE.match(physical_root):
        raise ValidationError(ERR_RUNTIME_UNSUPPORTED)

    checker = (
        _has_required_api_capabilities
        if capability_checker is None
        else capability_checker
    )
    try:
        capable = checker()
    except Exception:
        raise ValidationError(ERR_API_CAPABILITY) from None
    if not capable:
        raise ValidationError(ERR_API_CAPABILITY)


def _directory_open_flags() -> int:
    return os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC


def _validate_directory_metadata(metadata: os.stat_result) -> None:
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or metadata.st_uid != os.geteuid()
        or stat.S_IMODE(metadata.st_mode) & 0o022
    ):
        raise ValidationError(ERR_DIRECTORY)


def _open_checked_directory(name: str, *, parent_fd: int | None = None) -> int:
    try:
        if parent_fd is None:
            directory_fd = os.open(name, _directory_open_flags())
        else:
            directory_fd = os.open(name, _directory_open_flags(), dir_fd=parent_fd)
    except OSError:
        raise ValidationError(ERR_DIRECTORY) from None
    try:
        _validate_directory_metadata(os.fstat(directory_fd))
    except Exception:
        try:
            os.close(directory_fd)
        except OSError:
            pass
        raise ValidationError(ERR_DIRECTORY) from None
    return directory_fd


def _open_root_directory(root: Path) -> int:
    root_fd: int | None = None
    try:
        root_fd = os.open(os.fspath(root), _directory_open_flags())
        root_metadata = os.fstat(root_fd)
        cwd_metadata = os.stat(".")
        # derive後のancestor差し替えで別directoryを掴まないよう、open済みrootと
        # cwdのinode一致を再確認してから子directoryへ進む。
        if not stat.S_ISDIR(root_metadata.st_mode) or (
            root_metadata.st_dev,
            root_metadata.st_ino,
        ) != (cwd_metadata.st_dev, cwd_metadata.st_ino):
            raise OSError(errno.ENOTDIR, "repository root is not a directory")
    except OSError:
        if root_fd is not None:
            try:
                os.close(root_fd)
            except OSError:
                pass
        raise ValidationError(ERR_DIRECTORY) from None
    return root_fd


def open_category_directory(root: Path, category: str) -> int:
    """検証済み.ai-work/categoryを開き、category fdだけを返す。"""
    validate_category(category)
    root_fd: int | None = None
    ai_work_fd: int | None = None
    try:
        root_fd = _open_root_directory(root)
        ai_work_fd = _open_checked_directory(".ai-work", parent_fd=root_fd)
        category_fd = _open_checked_directory(category, parent_fd=ai_work_fd)
    except Exception:
        raise
    finally:
        for directory_fd in (ai_work_fd, root_fd):
            if directory_fd is not None:
                try:
                    os.close(directory_fd)
                except OSError:
                    pass

    # pathを再解決するとTOCTOU余地が戻るため、以後は確認済みcategory fdを
    # staging・publish・cleanupのnamespace境界として使い続ける。
    return category_fd


def scan_staging_residue(category_fd: int) -> None:
    """指定categoryだけを上限付きでscanし、既存stagingを拒否する。"""
    count = 0
    residue_found = False
    try:
        with os.scandir(category_fd) as entries:
            for entry in entries:
                count += 1
                if count > MAX_RESIDUE_ENTRIES:
                    raise ValidationError(ERR_RESIDUE_SCAN_LIMIT)
                if not isinstance(entry.name, str):
                    raise ValidationError(ERR_RESIDUE_SCAN_FAILED)
                entry.stat(follow_symlinks=False)
                if entry.name.startswith(STAGING_PREFIX):
                    residue_found = True
    except ValidationError:
        raise
    except Exception:
        raise ValidationError(ERR_RESIDUE_SCAN_FAILED) from None
    if residue_found:
        raise ValidationError(ERR_STAGING_RESIDUE)


def ensure_target_absent(category_fd: int, filename: str) -> None:
    try:
        os.stat(filename, dir_fd=category_fd, follow_symlinks=False)
    except FileNotFoundError:
        return
    except OSError:
        raise ValidationError(ERR_DIRECTORY) from None
    raise ValidationError(ERR_TARGET_EXISTS)


def create_staging_file(
    category_fd: int, token_generator: Callable[[int], str] = secrets.token_hex
) -> StagingFile:
    flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW | os.O_CLOEXEC
    for _ in range(MAX_STAGING_ATTEMPTS):
        token = token_generator(16)
        if not isinstance(token, str) or not STAGING_TOKEN_RE.fullmatch(token):
            raise ValidationError(ERR_INTERNAL)
        staging_name = STAGING_PREFIX + token
        try:
            staging_fd = os.open(staging_name, flags, 0o600, dir_fd=category_fd)
        except OSError as error:
            if error.errno == errno.EEXIST:
                continue
            raise ValidationError(ERR_STAGING_CREATE) from None
        # prefix一致では所有権を推定せず、このprocessがO_EXCL作成に成功した
        # nameだけをcleanup対象として呼出元へ返す。
        return StagingFile(staging_name, staging_fd)
    raise ValidationError(ERR_STAGING_COLLISION_LIMIT)


def write_all(file_fd: int, content: bytes) -> None:
    """short writeを処理し、全byteを書き切る。"""
    view = memoryview(content)
    offset = 0
    # os.writeは要求byte数未満を書いて正常returnし得るため、1回で全内容を
    # 書ける前提を置かず、全byteを書き切るまでloopする。
    while offset < len(view):
        try:
            written = os.write(file_fd, view[offset:])
        except OSError:
            raise ValidationError(ERR_WRITE) from None
        # writeが進捗なしで戻った場合に無限loopしない。
        if written <= 0 or written > len(view) - offset:
            raise ValidationError(ERR_WRITE)
        offset += written


def _cleanup_owned_staging(category_fd: int, staging_name: str) -> str | None:
    try:
        os.unlink(staging_name, dir_fd=category_fd)
    except OSError:
        return ERR_STAGING_UNLINK
    try:
        os.fsync(category_fd)
    except OSError:
        return ERR_CLEANUP_FSYNC
    return None


def _failed_before_publish(
    category_fd: int, staging_name: str, primary_error: str
) -> PublishResult:
    cleanup_error = _cleanup_owned_staging(category_fd, staging_name)
    if cleanup_error is not None:
        return PublishResult(PublishState.FAILED_WITH_RESIDUE, cleanup_error)
    return PublishResult(PublishState.FAILED, primary_error)


def _post_link_diagnostic(category_fd: int, staging_name: str, filename: str) -> None:
    try:
        staging_stat = os.stat(staging_name, dir_fd=category_fd, follow_symlinks=False)
        final_stat = os.stat(filename, dir_fd=category_fd, follow_symlinks=False)
    except OSError:
        raise ValidationError(ERR_POST_LINK_DIAGNOSTIC) from None
    if (
        not stat.S_ISREG(final_stat.st_mode)
        or stat.S_IMODE(final_stat.st_mode) != 0o600
        or staging_stat.st_dev != final_stat.st_dev
        or staging_stat.st_ino != final_stat.st_ino
    ):
        raise ValidationError(ERR_POST_LINK_DIAGNOSTIC)


def atomic_publish(category_fd: int, filename: str, content: bytes) -> PublishResult:
    """category fd内でno-overwrite publishを行い、terminal stateを返す。"""
    state = PublishState.PRE_PUBLISH
    try:
        scan_staging_residue(category_fd)
        ensure_target_absent(category_fd, filename)
        staging = create_staging_file(category_fd)
    except ValidationError as error:
        return PublishResult(PublishState.FAILED, error.code)

    write_fd: int | None = staging.fd
    try:
        write_all(write_fd, content)
        try:
            os.fsync(write_fd)
        except OSError:
            raise ValidationError(ERR_FILE_FSYNC) from None

        fd_to_close = write_fd
        write_fd = None
        try:
            os.close(fd_to_close)
        except OSError:
            raise ValidationError(ERR_FILE_CLOSE) from None

        try:
            # 事前確認だけではraceを防げないため、kernelのhard-link EEXISTを
            # 最終no-overwrite境界として既存destinationを変更させない。
            os.link(
                staging.name,
                filename,
                src_dir_fd=category_fd,
                dst_dir_fd=category_fd,
                follow_symlinks=False,
            )
        except OSError as error:
            code = ERR_TARGET_EXISTS if error.errno == errno.EEXIST else ERR_LINK
            raise ValidationError(code) from None
    except ValidationError as error:
        if write_fd is not None:
            fd_to_close = write_fd
            write_fd = None
            try:
                os.close(fd_to_close)
            except OSError:
                if error.code not in {ERR_WRITE, ERR_FILE_FSYNC}:
                    error = ValidationError(ERR_FILE_CLOSE)
        return _failed_before_publish(category_fd, staging.name, error.code)

    try:
        # 意図したstaging inodeが0600のfinalになったことを確認する。診断不能時に
        # cleanupすると人間が状態確認するための証拠を失うため、そのまま停止する。
        _post_link_diagnostic(category_fd, staging.name, filename)
    except ValidationError:
        return PublishResult(PublishState.INDETERMINATE, ERR_POST_LINK_DIAGNOSTIC)

    try:
        os.fsync(category_fd)
    except OSError:
        # link後のdurabilityが不明なままstagingを消すとfinal/staging双方から
        # 状態確認できなくなるため、INDETERMINATEではcleanupしない。
        return PublishResult(PublishState.INDETERMINATE, ERR_PUBLISH_FSYNC)

    state = PublishState.PUBLISHED_DURABLE
    # diagnosticとpublish fsync後だけcleanupし、durable finalを先に確定する。
    if state is PublishState.PUBLISHED_DURABLE:
        cleanup_error = _cleanup_owned_staging(category_fd, staging.name)
        if cleanup_error is not None:
            return PublishResult(PublishState.PUBLISHED_WITH_RESIDUE, cleanup_error)
    return PublishResult(PublishState.COMPLETE)


def execute_save(category: str, filename: str, content: bytes) -> PublishResult:
    """runtime境界を検証してatomic publishを実行する。"""
    category_fd: int | None = None
    try:
        root = derive_repository_root()
        validate_runtime(root)
        category_fd = open_category_directory(root, category)
        return atomic_publish(category_fd, filename, content)
    except ValidationError as error:
        return PublishResult(PublishState.FAILED, error.code)
    except Exception:
        return PublishResult(PublishState.FAILED, ERR_INTERNAL)
    finally:
        if category_fd is not None:
            try:
                os.close(category_fd)
            except OSError:
                pass


def build_save_success_output(
    category: str, filename: str, byte_count: int, confirmation_digest: str
) -> bytes:
    return (
        f"status: {PublishState.COMPLETE.value}\n"
        f"category: {category}\n"
        f"filename: {filename}\n"
        f"path: .ai-work/{category}/{filename}\n"
        f"saved-byte-count: {byte_count}\n"
        f"confirmation-digest: {confirmation_digest}\n"
    ).encode("ascii")


def build_save_error_output(result: PublishResult) -> str:
    code = result.error_code or ERR_INTERNAL
    reason = SAFE_REASONS.get(code, "Save request rejected")
    return f"status: {result.state.value}\nerror-code: {code}\nreason: {reason}\n"


def parse_command(argv: Sequence[str]) -> ParsedCommand:
    """preflight/saveの固定argv schemaだけを受理する。"""
    values = list(argv)
    if (
        values
        and values[0] == "preflight"
        and (
            len(values) != 6
            or any(not isinstance(value, str) for value in values)
            or values[1] != "--category"
            or values[3] != "--filename"
            or not values[5].startswith("--content-base64url=")
        )
    ):
        raise ValidationError(ERR_ARGUMENT_SCHEMA)
    if values and values[0] == "preflight":
        category = validate_category(values[2])
        filename = validate_filename(values[4])
        payload = values[5].removeprefix("--content-base64url=")
        return ParsedCommand("preflight", category, filename, None, payload)

    if (
        len(values) != 8
        or any(not isinstance(value, str) for value in values)
        or values[0] != "save"
        or values[1] != "--category"
        or values[3] != "--filename"
        or values[5] != "--confirmation-digest"
        or not values[7].startswith("--content-base64url=")
    ):
        raise ValidationError(ERR_ARGUMENT_SCHEMA)
    category = validate_category(values[2])
    filename = validate_filename(values[4])
    confirmation_digest = validate_confirmation_digest(values[6])
    payload = values[7].removeprefix("--content-base64url=")
    return ParsedCommand("save", category, filename, confirmation_digest, payload)


def main(argv: Sequence[str] | None = None) -> int:
    """trusted preflightまたはdigest確認済みsaveを実行する。"""
    arguments = list(sys.argv[1:] if argv is None else argv)
    save_requested = bool(arguments) and arguments[0] == "save"
    try:
        command = parse_command(arguments)
        normalized_content = decode_normalize_validate_content(
            command.content_base64url
        )
        confirmation_digest = compute_confirmation_digest(
            command.category, command.filename, normalized_content
        )
        if command.mode == "preflight":
            output = build_preflight_output(
                command.category, command.filename, normalized_content
            )
        else:
            if command.confirmation_digest != confirmation_digest:
                raise ValidationError(ERR_CONFIRMATION_MISMATCH)
            result = execute_save(
                command.category, command.filename, normalized_content
            )
            if result.state is not PublishState.COMPLETE:
                sys.stderr.write(build_save_error_output(result))
                sys.stderr.flush()
                return 1
            output = build_save_success_output(
                command.category,
                command.filename,
                len(normalized_content),
                confirmation_digest,
            )
    except ValidationError as error:
        if save_requested:
            sys.stderr.write(
                build_save_error_output(PublishResult(PublishState.FAILED, error.code))
            )
        else:
            print(error.code, file=sys.stderr)
        return 1
    except Exception:
        if save_requested:
            sys.stderr.write(
                build_save_error_output(
                    PublishResult(PublishState.FAILED, ERR_INTERNAL)
                )
            )
        else:
            print(ERR_INTERNAL, file=sys.stderr)
        return 1

    try:
        sys.stdout.buffer.write(output)
        sys.stdout.buffer.flush()
    except Exception:
        print(ERR_INTERNAL, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
