<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;
use Throwable;

/**
 * @purpose 运动模块分布式计算-服务端
 * @author yanglong
 */
final class MotionWorkerServer
{
    public function run(string $address, ?float $idle = null): void
    {
        $server = @stream_socket_server($address, $errno, $error);
        if ($server === false) throw new RuntimeException("Unable to listen on {$address}: {$error} ({$errno})");
        stream_set_blocking($server, false);
        $connections = [];
        $accepted = false;
        $since = microtime(true);
        while (true) {
            if ($accepted && $idle !== null && !$connections && microtime(true) - $since >= $idle) {
                fclose($server);
                return;
            }
            $read = [$server];
            $write = [];
            foreach ($connections as $connection) {
                $read[] = $connection['socket'];
                if ($connection['output'] !== '') $write[] = $connection['socket'];
            }
            $except = null;
            if (@stream_select($read, $write, $except, 0, 1) === false) continue;
            if (in_array($server, $read, true)) {
                while (($socket = @stream_socket_accept($server, 0)) !== false) {
                    stream_set_blocking($socket, false);
                    $connections[(int)$socket] = ['socket' => $socket, 'input' => '', 'output' => '', 'frames' => []];
                    $accepted = true;
                }
                $read = array_filter($read, fn($socket) => $socket !== $server);
            }
            foreach ($read as $socket) {
                $connectionId = (int)$socket;
                if (!isset($connections[$connectionId])) continue;
                $data = @fread($socket, 65536);
                if ($data === false || ($data === '' && feof($socket))) {
                    fclose($socket);
                    unset($connections[$connectionId]);
                    continue;
                }
                $connections[$connectionId]['input'] .= $data;
                foreach (MotionWorkerProtocol::takeFrames($connections[$connectionId]['input']) as $body) {
                    $request = 0;
                    try {
                        $message = MotionWorkerProtocol::decodeRequest($body);
                        if ($message[0] === MotionWorkerProtocol::LOAD_REFERENCE) {
                            [, $frameId, $width, $height, $aw, $ah, $refY, $refU, $refV] = $message;
                            $connections[$connectionId]['frames'] = [$frameId => [$width, $height, $aw, $ah, $refY, $refU, $refV]];
                            continue;
                        }
                        [, $frameId, $request, $qp, $blocks] = $message;
                        if (!isset($connections[$connectionId]['frames'][$frameId])) throw new RuntimeException('Unknown motion worker frame id');
                        [$width, $height, $aw, $ah, $refY, $refU, $refV] = $connections[$connectionId]['frames'][$frameId];
                        $helper = new MotionWorkerHelper($width, $height, $aw, $ah, $qp, $refY, $refU, $refV);
                        $result = [];
                        foreach ($blocks as $index => $block) $result[$index] = $helper->prepare($block);
                        $connections[$connectionId]['output'] .= MotionWorkerProtocol::response($request, $result);
                    } catch (Throwable $exception) {
                        fwrite(STDERR, "Motion worker request {$request} failed: {$exception->getMessage()}\n");
                        $connections[$connectionId]['output'] .= MotionWorkerProtocol::error($request, $exception->getMessage());
                    }
                }
            }
            foreach ($write as $socket) {
                $connectionId = (int)$socket;
                if (!isset($connections[$connectionId]) || $connections[$connectionId]['output'] === '') continue;
                $written = @fwrite($socket, $connections[$connectionId]['output']);
                if ($written === false || ($written === 0 && feof($socket))) {
                    fclose($socket);
                    unset($connections[$connectionId]);
                } elseif ($written > 0) {
                    $connections[$connectionId]['output'] = substr($connections[$connectionId]['output'], $written);
                }
            }
        }
    }
}

final class MotionWorkerHelper
{
    private const INTERP_TAP0 = 1;
    private const INTERP_TAP1 = -5;
    private const INTERP_TAP2 = 20;
    private const INTERP_TAP3 = 20;
    private const INTERP_TAP4 = -5;
    private const INTERP_TAP5 = 1;
    use MotionTrait, TransformTrait, InterPredTrait;
    private const DEQUANT4_COEFF_INIT = [[10,13,16],[11,14,18],[13,16,20],[14,18,23],[16,20,25],[18,23,29]];
    private const QUANT_MF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_MF;
    private const QUANT_INTER_FF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_INTER_FF;
    private const ZIGZAG_SCAN_4X4 = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::ZIGZAG_SCAN_4X4;
    public int $width;
    public int $height;
    public int $mbAlignedWidth;
    public int $mbAlignedHeight;
    public int $qp;
    public array $dequant4Table = [];
    public $refInts = null;

    public function __construct(int $width, int $height, int $aw, int $ah, int $qp, private string $refY, private string $refU, private string $refV)
    {
        $this->width = $width;
        $this->height = $height;
        $this->mbAlignedWidth = $aw;
        $this->mbAlignedHeight = $ah;
        $this->qp = $qp;
        $positionClass = [0,1,0,1,1,2,1,2,0,1,0,1,1,2,1,2];
        $this->dequant4Table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
        for ($i = 0; $i < 6; $i++) for ($q = 0; $q < 52; $q++) {
            $shift = intdiv($q, 6) + 2;
            $index = $q % 6;
            for ($x = 0; $x < 16; $x++) $this->dequant4Table[$i][$q][$x] = (self::DEQUANT4_COEFF_INIT[$index][$positionClass[$x]] * 16) << $shift;
        }
    }

    public function prepare(array $job): array
    {
        return $this->preparePMacroblock($job[0], $job[1], $job[2], $this->refY, $this->refU, $this->refV, $job[3]);
    }
}
