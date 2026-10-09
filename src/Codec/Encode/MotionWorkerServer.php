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
                            [, $seq, $width, $height, $aw, $ah, $refY, $refU, $refV] = $message;
                            $connections[$connectionId]['frames'] = [$seq => [$width, $height, $aw, $ah, $refY, $refU, $refV]];
                            continue;
                        }
                        [, $seq, $request, $qp, $blocks] = $message;
                        if (!isset($connections[$connectionId]['frames'][$seq])) throw new RuntimeException('Unknown motion worker reference seq');
                        [$width, $height, $aw, $ah, $refY, $refU, $refV] = $connections[$connectionId]['frames'][$seq];
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


