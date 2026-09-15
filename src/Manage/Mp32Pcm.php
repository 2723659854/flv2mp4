<?php
namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Mp3\Mp3L3Decoder;
use RuntimeException;

/**
 * @purpose mp3提取pcm（S16LE 交错）
 * @author yanglong
 * @time 2026年9月8日15:08:46
 */
final class Mp32Pcm
{
    private string $inputFile;
    private string $outputFile;
    private int $sampleRate = 0;
    private int $channels = 0;

    /**
     * @param string $inputFile mp3文件
     * @param string $outputFile pcm文件
     */
    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) throw new RuntimeException("MP3文件不存在: {$inputFile}");
        $this->inputFile = $inputFile; $this->outputFile = $outputFile;
    }

    public function run(): array
    {
        $data = file_get_contents($this->inputFile);
        if ($data === false) throw new RuntimeException('无法读取 MP3 文件');

        $decoded = (new Mp3L3Decoder())->decode($data);
        $this->sampleRate = $decoded['sampleRate'];
        $this->channels = $decoded['channels'];
        $pcm = $this->interleaveS16($decoded['planes'], $this->channels);

        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException("无法创建输出目录: {$dir}");
        if (file_put_contents($this->outputFile, $pcm) === false) throw new RuntimeException('无法写入 PCM 文件');
        return ['output' => $this->outputFile, 'sampleRate' => $this->sampleRate, 'channels' => $this->channels, 'bytes' => strlen($pcm)];
    }

    /**
     * planar float -> 交错 S16LE
     * @param float[][] $planes
     */
    private function interleaveS16(array $planes, int $channels): string
    {
        $frames = count($planes[0]);
        $out = '';
        for ($n = 0; $n < $frames; $n++) {
            for ($ch = 0; $ch < $channels; $ch++) {
                $v = (int) round($planes[$ch][$n] * 32767.0);
                if ($v > 32767) $v = 32767;
                else if ($v < -32768) $v = -32768;
                $out .= pack('v', $v < 0 ? $v + 65536 : $v);
            }
        }
        return $out;
    }
}
