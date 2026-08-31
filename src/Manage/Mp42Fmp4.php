<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\MP4\MP4;
use Xiaosongshu\Flv2mp4\MP4\MP4Remuxer;

/**
 * @purpose 将普通 MP4 直接封装为 fMP4 HLS
 * @author yanglong
 * @time 2026年8月31日14:05:04
 */
class Mp42Fmp4
{
    private string $inputFile;
    private string $outputDir;
    private $inputHandle;
    private array $tree = [];
    private int $size = 0;
    private ?array $video = null;
    private ?array $audio = null;
    private array $metas = [];

    public function __construct(string $inputFile, string $outputDir)
    {
        if (!is_file($inputFile)) throw new \RuntimeException("MP4文件不存在: {$inputFile}");
        $this->inputFile = $inputFile;
        $this->outputDir = rtrim($outputDir, '/\\');
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0777, true) && !is_dir($this->outputDir)) {
            throw new \RuntimeException("无法创建输出目录: {$outputDir}");
        }
    }

    public function run(int $targetSegmentDuration = 4000): array
    {
        $this->inputHandle = fopen($this->inputFile, 'rb');
        if (!$this->inputHandle) throw new \RuntimeException('无法读取 MP4');
        $this->size = (int)fstat($this->inputHandle)['size'];
        try {
            $parser = new Mp4ToFlv($this->inputFile, $this->outputDir . DIRECTORY_SEPARATOR . '.unused.flv');
            $r = new \ReflectionClass($parser);
            $this->set($r, $parser, 'inputHandle', $this->inputHandle);
            $this->set($r, $parser, 'inputSize', $this->size);
            $this->call($r, $parser, 'parseMp4Boxes');
            $this->call($r, $parser, 'parseTracks');
            $this->tree = $this->get($r, $parser, 'boxTree');
            $this->video = $this->get($r, $parser, 'videoTrack');
            $this->audio = $this->get($r, $parser, 'audioTrack');
            $this->buildMeta($r, $parser);
            if (!$this->metas) throw new \RuntimeException('未找到 H264/AAC 轨道');
            $init = MP4::generateInitSegment($this->metas);
            $this->clear();
            $this->write('init.mp4', $init);
            $files = [];
            $segmentHandle = null; $segmentName = null; $segmentBytes = 0;
            $start = -1; $end = 0; $index = 1; $ready = false;
            $flush = function () use (&$segmentHandle, &$segmentName, &$segmentBytes, &$start, &$end, &$index, &$ready, &$files): void {
                if (!is_resource($segmentHandle) || $segmentBytes === 0) return;
                if (!fflush($segmentHandle)) throw new \RuntimeException('无法刷新媒体片段');
                fclose($segmentHandle);
                $files[] = ['name' => $segmentName, 'duration' => max(0, $end - $start)];
                $segmentHandle = null; $segmentName = null; $segmentBytes = 0;
                $start = -1; $end = 0; $ready = false;
            };
            $outputDir = $this->outputDir;
            $remuxer = new MP4Remuxer(['isLive' => false]);
            $remuxer->_videoMeta = $this->meta('video');
            $remuxer->_audioMeta = $this->meta('audio');
            $remuxer->setOnMediaSegment(function (string $track, array $value) use (&$segmentHandle, &$segmentName, &$segmentBytes, &$start, &$end, &$ready, &$index, $outputDir, $targetSegmentDuration, $flush): void {
                $info = $value['info'];
                $begin = (int)($info->originalBeginDts ?? $info->beginDts);
                $finish = (int)($info->originalEndDts ?? $info->endDts);
                if ($segmentBytes > 0 && $track === 'video' && ($value['isKeyframe'] ?? false) && $ready) $flush();
                if (!is_resource($segmentHandle)) {
                    $segmentName = 'segment_' . $index . '.m4s';
                    $index++;
                    $segmentHandle = fopen($outputDir . DIRECTORY_SEPARATOR . $segmentName, 'wb');
                    if ($segmentHandle === false) throw new \RuntimeException('无法创建媒体片段');
                }
                $this->writeHandle($segmentHandle, $value['data']);
                $segmentBytes += strlen($value['data']);
                if ($start < 0) $start = $begin;
                $end = max($end, $finish);
                if ($end - $start >= $targetSegmentDuration) $ready = true;
            });
            $vIndexes = $this->video ? $this->call($r, $parser, 'extractSamplesFromStbl', [$this->nested($this->findTrack($this->tree, $this->video['id']), 'stbl'), 'vide']) : [];
            $aIndexes = $this->audio ? $this->call($r, $parser, 'extractSamplesFromStbl', [$this->nested($this->findTrack($this->tree, $this->audio['id']), 'stbl'), 'soun']) : [];
            $batchSize = 100;
            $count = max(count($vIndexes), count($aIndexes));
            for ($offset = 0; $offset < $count; $offset += $batchSize) {
                $v = $this->makeTrack($this->video ? $this->meta('video') : null, array_slice($vIndexes, $offset, $batchSize), 'vide');
                $a = $this->makeTrack($this->audio ? $this->meta('audio') : null, array_slice($aIndexes, $offset, $batchSize), 'soun');
                $remuxer->remux($a, $v);
            }
            unset($vIndexes, $aIndexes);
            $flush();
            if (!$files) throw new \RuntimeException('未生成媒体片段');
            foreach ($files as $file) {
                $path = $this->outputDir . DIRECTORY_SEPARATOR . $file['name'];
                if (!is_file($path) || filesize($path) === 0) {
                    throw new \RuntimeException("媒体片段未成功写入: {$path}");
                }
            }
            $this->write('index.m3u8', $this->m3u8($files));
            return ['index' => $this->outputDir . DIRECTORY_SEPARATOR . 'index.m3u8', 'outputDir' => $this->outputDir, 'init' => $this->outputDir . DIRECTORY_SEPARATOR . 'init.mp4', 'segments' => array_map(fn($x) => $this->outputDir . DIRECTORY_SEPARATOR . $x['name'], $files)];
        } finally {
            if (is_resource($segmentHandle ?? null)) fclose($segmentHandle);
            fclose($this->inputHandle);
            $this->inputHandle = null;
        }
    }

    private function buildMeta(\ReflectionClass $r, object $p): void
    {
        foreach ([['track' => $this->video, 'type' => 'video'], ['track' => $this->audio, 'type' => 'audio']] as $item) {
            if (!$item['track']) continue;
            $t = $item['track']; $meta = ['id' => $t['id'], 'type' => $item['type'], 'timescale' => 1000, 'duration' => 0, 'sequenceNumber' => 1, 'addcoefficient' => 1, 'samples' => [], 'length' => 0, 'refSampleDuration' => $item['type'] === 'audio' ? 1024 : 3600];
            if ($item['type'] === 'video') {
                $meta['codec'] = 'avc1.' . bin2hex(substr($this->get($r, $p, 'sps'), 1, 3)); $meta['sps'] = [$this->get($r, $p, 'sps')]; $meta['pps'] = [$this->get($r, $p, 'pps')]; $meta['avcc'] = $this->avcc($meta['sps'][0], $meta['pps'][0]); $meta['codecWidth'] = $meta['presentWidth'] = 0; $meta['codecHeight'] = $meta['presentHeight'] = 0;
            } else { $meta['codec'] = 'mp4a.40.2'; $meta['config'] = $this->get($r, $p, 'audioSpecificConfig') ?: "\x12\x10"; $meta['channelCount'] = $this->get($r, $p, 'audioChannels') ?: 2; $meta['audioSampleRate'] = $this->get($r, $p, 'audioSampleRate') ?: 44100; }
            $this->metas[] = $meta;
        }
    }

    private function makeTrack(?array $track, array $samples, string $type): array
    {
        if (!$track) return ['samples' => []];
        $out = [];
        foreach ($samples as $s) {
            $data = $this->read($s['offset'], $s['size']);
            $unit = $type === 'vide' ? $this->nalUnits($data) : $data;
            $out[] = ['unit' => $type === 'vide' ? null : $unit, 'units' => $type === 'vide' ? $unit : [], 'dts' => $s['dtsMs'], 'cts' => $s['ctsMs'], 'isKeyframe' => $s['keyframe'], 'duration' => 0];
        }
        $track['samples'] = $out;
        return $track;
    }

    private function meta(string $type): array { foreach ($this->metas as $m) if ($m['type'] === $type) return $m; return []; }
    private function nalUnits(string $data): array { $out=[]; for($p=0,$n=strlen($data);$p+4<=$n;){$l=unpack('N',substr($data,$p,4))[1];$p+=4;if($l<=0||$p+$l>$n)break;$out[]=['data'=>substr($data,$p,$l)];$p+=$l;}return $out; }
    private function avcc(string $sps,string $pps): string{return "\x01".($sps[1]??"\x42").($sps[2]??"\x00").($sps[3]??"\x1f")."\xff\xe1".pack('n',strlen($sps)).$sps."\x01".pack('n',strlen($pps)).$pps;}
    private function m3u8(array $fs): string { $max=1;$x=['#EXTM3U','#EXT-X-VERSION:7','#EXT-X-INDEPENDENT-SEGMENTS','#EXT-X-MAP:URI="init.mp4"'];foreach($fs as $f){$d=max(.001,round($f['duration']/1000,3));$max=max($max,$d);$x[]="#EXTINF:{$d},";$x[]=$f['name'];}$x[]='#EXT-X-TARGETDURATION:'.(int)ceil($max);$x[]='#EXT-X-ENDLIST';return implode("\n",$x)."\n";}
    private function clear(): void { foreach(glob($this->outputDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) if(is_file($f)) @unlink($f); }
    private function write(string $name,string $data): void { if(file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . $name,$data)===false)throw new \RuntimeException('无法写入输出'); }
    private function writeHandle($handle, string $data): void { for ($offset = 0, $length = strlen($data); $offset < $length;) { $written = fwrite($handle, substr($data, $offset)); if ($written === false || $written === 0) throw new \RuntimeException('无法写入媒体片段'); $offset += $written; } }
    private function read(int $o,int $n): string { fseek($this->inputHandle,$o);$d='';while(strlen($d)<$n){$x=fread($this->inputHandle,$n-strlen($d));if($x===false||$x==='')throw new \RuntimeException('MP4 sample 读取不完整');$d.=$x;}return $d; }
    private function nested(?array $b,string $t): ?array { foreach($b['children']??[] as $c){if($c['type']===$t)return$c;$x=$this->nested($c,$t);if($x)return$x;}return null; }
    private function findTrack(array $bs,int $id): ?array { foreach($bs as $b){if($b['type']==='trak'){ $tk=$this->nested($b,'tkhd');if($tk&&unpack('N',substr($tk['data'],12,4))[1]===$id)return$b;}if($b['children']&&($x=$this->findTrack($b['children'],$id)))return$x;}return null; }
    private function get(\ReflectionClass $r,object $o,string $n): mixed{$p=$r->getProperty($n);$p->setAccessible(true);return$p->getValue($o);}
    private function set(\ReflectionClass $r,object $o,string $n,mixed $v):void{$p=$r->getProperty($n);$p->setAccessible(true);$p->setValue($o,$v);}
    private function call(\ReflectionClass $r,object $o,string $n,array $a=[]):mixed{$m=$r->getMethod($n);$m->setAccessible(true);return$m->invokeArgs($o,$a);}
}
