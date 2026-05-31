<?php

namespace Xiaosongshu\Flv2mp4\Flv;

/**
 * @purpose 指数哥伦布缓冲解码器
 * @author yanglong
 */
class ExpGolomb
{
    public $_buffer;
    public $_buffer_index;
    public $_total_bytes;
    public $_total_bits;
    public $_current_word;
    public $_current_word_bits_left;

    public function __construct($uint8array)
    {
        $this->_buffer = $uint8array;
        $this->_buffer_index = 0;
        $this->_total_bytes = strlen($uint8array);
        $this->_total_bits = $this->_total_bytes * 8;
        $this->_current_word = 0;
        $this->_current_word_bits_left = 0;
    }

    public function destroy()
    {
        $this->_buffer = null;
    }

    public function _fillCurrentWord()
    {
        $buffer_bytes_left = $this->_total_bytes - $this->_buffer_index;
        if ($buffer_bytes_left <= 0) {
            throw new \RuntimeException('ExpGolomb: _fillCurrentWord() but no bytes available');
        }

        $bytes_read = min(4, $buffer_bytes_left);
        $bytes = substr($this->_buffer, $this->_buffer_index, $bytes_read);
        $word = str_pad($bytes, 4, "\0", STR_PAD_RIGHT);
        $unpacked = unpack('N', $word);
        $this->_current_word = $unpacked[1];
        $this->_buffer_index += $bytes_read;
        $this->_current_word_bits_left = $bytes_read * 8;
    }

    public static function uRShift32($val, $bits)
    {
        $val &= 0xFFFFFFFF;
        if ($bits == 0) return $val;
        if ($bits >= 32) return 0;
        return ($val >> $bits) & ((1 << (32 - $bits)) - 1);
    }

    public function readBits($bits)
    {
        if ($bits > 32) {
            throw new \InvalidArgumentException('ExpGolomb: readBits() bits exceeded max 32bits!');
        }
        if ($bits <= $this->_current_word_bits_left) {
            $result = self::uRShift32($this->_current_word, 32 - $bits);
            $this->_current_word = ($this->_current_word << $bits) & 0xFFFFFFFF;
            $this->_current_word_bits_left -= $bits;
            return $result;
        }
        $result = $this->_current_word_bits_left ? $this->_current_word : 0;
        $result = self::uRShift32($result, 32 - $this->_current_word_bits_left);
        $bits_need_left = $bits - $this->_current_word_bits_left;
        $this->_fillCurrentWord();
        $bits_read_next = min($bits_need_left, $this->_current_word_bits_left);
        $result2 = self::uRShift32($this->_current_word, 32 - $bits_read_next);
        $this->_current_word = ($this->_current_word << $bits_read_next) & 0xFFFFFFFF;
        $this->_current_word_bits_left -= $bits_read_next;
        $result = (($result << $bits_read_next) | $result2) & 0xFFFFFFFF;
        return $result;
    }

    public function readBool()
    {
        return $this->readBits(1) === 1;
    }

    public function readByte()
    {
        return $this->readBits(8);
    }

    public function _skipLeadingZero()
    {
        $zero_count = 0;
        while ($zero_count < $this->_current_word_bits_left) {
            if (($this->_current_word & (0x80000000 >> $zero_count)) !== 0) {
                $this->_current_word = ($this->_current_word << $zero_count) & 0xFFFFFFFF;
                $this->_current_word_bits_left -= $zero_count;
                return $zero_count;
            }
            $zero_count++;
        }
        $this->_fillCurrentWord();
        return $zero_count + $this->_skipLeadingZero();
    }

    public function readUEG()
    {
        $leading_zeros = $this->_skipLeadingZero();
        return $this->readBits($leading_zeros + 1) - 1;
    }

    public function readSEG()
    {
        $value = $this->readUEG();
        if ($value & 0x01) {
            return self::uRShift32($value + 1, 1);
        } else {
            return -1 * self::uRShift32($value, 1);
        }
    }
}