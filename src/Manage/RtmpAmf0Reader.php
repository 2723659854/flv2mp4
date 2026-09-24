<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * @purpose 最小AMF0读取器：仅用于解析RTMP控制命令（_result / onStatus等），
 * 覆盖命令交互中实际出现的类型；遇到不支持的类型抛异常由调用方忽略。
 *
 * @internal 仅供 RtmpStreamPuller 使用
 * @author yanglong
 * @time 2026年9月24日11:30:46
 */
final class RtmpAmf0Reader
{
    private string $buf;
    private int $len;
    private int $pos = 0;

    public function __construct(string $payload)
    {
        $this->buf = $payload;
        $this->len = strlen($payload);
    }

    public function eof(): bool
    {
        return $this->pos >= $this->len;
    }

    public function read(): mixed
    {
        if ($this->pos >= $this->len) return null;
        $marker = ord($this->buf[$this->pos++]);
        switch ($marker) {
            case 0x00: // Number
                $this->need(8);
                $q = unpack('J', substr($this->buf, $this->pos, 8))[1];
                $this->pos += 8;
                return unpack('d', pack('P', $q))[1];

            case 0x01: // Boolean
                $this->need(1);
                return ord($this->buf[$this->pos++]) !== 0;

            case 0x02: // String
                return $this->readString16();

            case 0x03: // Object
            case 0x10: // Typed object（先跳过类名）
                if ($marker === 0x10) $this->readString16();
                return $this->readObjectBody();

            case 0x08: // ECMA Array（u32计数后为object体）
                $this->need(4);
                $this->pos += 4;
                return $this->readObjectBody();

            case 0x0A: // Strict Array
                $this->need(4);
                $count = unpack('N', substr($this->buf, $this->pos, 4))[1];
                $this->pos += 4;
                $arr = [];
                for ($i = 0; $i < $count; $i++) $arr[] = $this->read();
                return $arr;

            case 0x05: // Null
            case 0x06: // Undefined
                return null;

            case 0x0C: // Long String
                $this->need(4);
                $length = unpack('N', substr($this->buf, $this->pos, 4))[1];
                $this->pos += 4;
                $this->need($length);
                $s = substr($this->buf, $this->pos, $length);
                $this->pos += $length;
                return $s;

            default:
                throw new RuntimeException("不支持的AMF0类型: 0x" . dechex($marker));
        }
    }

    private function readObjectBody(): array
    {
        $obj = [];
        while ($this->pos < $this->len) {
            if ($this->pos + 3 <= $this->len
                && ord($this->buf[$this->pos]) === 0x00
                && ord($this->buf[$this->pos + 1]) === 0x00
                && ord($this->buf[$this->pos + 2]) === 0x09) {
                $this->pos += 3;
                break;
            }
            $key = $this->readString16();
            $obj[$key] = $this->read();
        }
        return $obj;
    }

    private function readString16(): string
    {
        $this->need(2);
        $length = unpack('n', substr($this->buf, $this->pos, 2))[1];
        $this->pos += 2;
        $this->need($length);
        $s = substr($this->buf, $this->pos, $length);
        $this->pos += $length;
        return $s;
    }

    private function need(int $bytes): void
    {
        if ($this->pos + $bytes > $this->len) throw new RuntimeException('AMF0数据不完整');
    }
}
