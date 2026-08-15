<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use RuntimeException;

/** 标识仅影响当前 Opus 包、可安全丢弃的解码或包格式异常。 */
final class OpusDecodeException extends RuntimeException
{
}
