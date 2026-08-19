<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use InvalidArgumentException;
use UnexpectedValueException;

final class MotionWorkerProtocol
{
    public const MAX_BODY_LENGTH = 16777216;
    public static function frame(string $body): string { $n = strlen($body); if ($n < 1 || $n > self::MAX_BODY_LENGTH) throw new InvalidArgumentException('Invalid motion worker frame'); return pack('N', $n) . $body; }
    public static function takeFrames(string &$buffer, int $limit = 16): array { $out=[]; while (count($out)<$limit && strlen($buffer)>=4) { $n=unpack('N',substr($buffer,0,4))[1]; if ($n<1||$n>self::MAX_BODY_LENGTH) throw new UnexpectedValueException('Invalid motion worker length'); if(strlen($buffer)<$n+4) break; $out[]=substr($buffer,4,$n); $buffer=substr($buffer,4+$n); } return $out; }
    public static function batch(int $id, int $width, int $height, int $alignedWidth, int $alignedHeight, int $qp, string $refY, string $refU, string $refV, array $blocks): string { return self::frame(serialize([$id,$width,$height,$alignedWidth,$alignedHeight,$qp,$refY,$refU,$refV,$blocks])); }
}
