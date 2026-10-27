<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * TMDB API Read Access Tokenの設定不備を表す例外。
 *
 * トークンが未設定、空文字、または不正な型の場合に使用する。
 * 認証情報の値は例外メッセージに含めない。
 */
class TmdbTokenNotConfiguredException extends RuntimeException {}
