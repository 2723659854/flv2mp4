## FLV 转码为 MP4 工具
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

### 介绍

这是一款纯 PHP 开发的工具，用于将 FLV 媒体文件转换为 MP4 格式，便于存储和后续处理。

### 安装

```bash
composer require xiaosongshu/flv2mp4
```

### 示例

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// 需要转换的flv媒体文件
$file = __DIR__."/test.flv";

// 示例1: 使用原有方法合并转换为单个MP4适配录播回放，同时也生成切片适配直播场景，插件自动清空目录重新生成切片
echo "=== 示例1: 合并转换为单个MP4 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n\n";
}

// 示例2: 生成分开的音视频切片（用于浏览器播放，主要适配直播），插件自动清空目录重新生成切片
echo "=== 示例2: 生成分开的音视频切片 ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runSeparate($file, $outputDir2);
    echo "\n转换完成！生成的文件:\n";
    echo "  音频初始化: " . ($res['audioInit'] ?? '无') . "\n";
    echo "  视频初始化: " . ($res['videoInit'] ?? '无') . "\n";
    echo "  音频切片数量: " . count($res['audioSegments']) . "\n";
    echo "  视频切片数量: " . count($res['videoSegments']) . "\n";
    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n";
}


```
普通MP4合成文件可以直接播放，普通的浏览器直接使用video标签可以播放，示例代码如下
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>播放视频</title>
</head>
<body>
<video id="video" controls width="800">
    <!-- 此处需要填写真是的文件路径 -->
    <source src="output_merge/demo.mp4" type="video/mp4">
    您的浏览器不支持 video 标签。
</video>
</body>
</html>
```
本插件还会生成fmp4切片，适用于直播，以下示例代码展示了两种切片方式如何播放，根据你的需求选用，以下是示例代码：
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>fMP4 Player - 分开音视频模式</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 20px; 
            background: #1a1a1a; 
            color: #fff; 
        }
        video { 
            width: 100%; 
            background: #000; 
            border-radius: 12px; 
            max-height: 600px;
        }
        .controls {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            background: #4CAF50;
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn:hover { background: #45a049; }
        .btn:disabled { background: #555; cursor: not-allowed; }
        .btn-danger { background: #f44336; }
        .btn-danger:hover { background: #da190b; }
        .btn-secondary { background: #607D8B; }
        .btn-secondary:hover { background: #546E7A; }
        #status { 
            margin-top: 15px; 
            padding: 15px; 
            background: rgba(255,255,255,0.05); 
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .status-info { color: #4CAF50; }
        .status-warning { color: #ff9800; }
        .status-error { color: #f44336; }
        .status-debug { color: #2196F3; }
        .mode-selector {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
        }
        .mode-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .mode-btn.active {
            background: #4CAF50;
            border-color: #4CAF50;
        }
        .mode-btn:hover:not(.active) {
            background: rgba(255,255,255,0.15);
        }
        .info-panel {
            margin-top: 15px;
            padding: 15px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            font-size: 14px;
        }
        .info-panel h3 {
            margin: 0 0 10px 0;
            color: #4CAF50;
        }
        .info-panel ul {
            margin: 0;
            padding-left: 20px;
        }
        .info-panel li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <h1>fMP4 流媒体播放器</h1>
    
    <!-- 模式选择 -->
    <div class="mode-selector">
        <button class="mode-btn active" data-mode="separate">分开音视频模式</button>
        <button class="mode-btn" data-mode="merged">合并模式</button>
    </div>

    <!-- 信息面板 -->
    <div class="info-panel">
        <h3>当前模式: <span id="modeInfo">分开音视频模式</span></h3>
        <ul>
            <li><strong>分开模式</strong>: 使用 audio_init.mp4 + video_init.mp4 + 分开的 m4s 切片</li>
            <li><strong>合并模式</strong>: 使用 init.mp4 + 合并的 m4s 切片</li>
            <li>输出目录: <code>/output_separate/</code> (分开模式) 或 <code>/output_merge/</code> (合并模式)</li>
        </ul>
    </div>

    <video id="video" controls autoplay muted playsinline></video>
    
    <div class="controls">
        <button id="playBtn" class="btn">播放</button>
        <button id="pauseBtn" class="btn" disabled>暂停</button>
        <button id="resetBtn" class="btn btn-danger">重置</button>
        <button id="debugBtn" class="btn btn-secondary">显示调试信息</button>
    </div>
    
    <div id="status">准备播放...</div>

<script>
const video = document.getElementById('video');
const statusDiv = document.getElementById('status');
const modeInfo = document.getElementById('modeInfo');

// 当前播放模式
let currentMode = 'separate';
let BASE_URL = '/output_separate/';

let mediaSource;
let audioSourceBuffer, videoSourceBuffer;
let streamEnded = false;
let segmentIdx = 1;
let isDebugMode = false;
let metaInfo = null;

// 添加状态日志
function addStatus(message, type = 'info') {
    const timestamp = new Date().toLocaleTimeString();
    const typeClass = `status-${type}`;
    statusDiv.innerHTML += `<div class="${typeClass}">[${timestamp}] ${message}</div>`;
    statusDiv.scrollTop = statusDiv.scrollHeight;
    console.log(`[fMP4] ${message}`);
}

// 追加数据到 SourceBuffer
async function appendBuffer(sourceBuffer, data, description) {
    return new Promise((resolve, reject) => {
        const onUpdateEnd = () => {
            sourceBuffer.removeEventListener('error', onError);
            addStatus(`${description} 追加成功 (${data.byteLength} bytes)`, 'debug');
            resolve();
        };
        
        const onError = (e) => {
            sourceBuffer.removeEventListener('updateend', onUpdateEnd);
            addStatus(`SourceBuffer 错误: ${e.type}`, 'error');
            reject(new Error('SourceBuffer error'));
        };

        // 等待 SourceBuffer 就绪
        const waitAndAppend = () => {
            if (sourceBuffer.updating) {
                setTimeout(waitAndAppend, 50);
                return;
            }
            
            try {
                sourceBuffer.addEventListener('updateend', onUpdateEnd, { once: true });
                sourceBuffer.addEventListener('error', onError, { once: true });
                sourceBuffer.appendBuffer(data);
                addStatus(`正在追加 ${description}...`, 'debug');
            } catch (e) {
                addStatus(`appendBuffer 异常: ${e.message}`, 'error');
                reject(e);
            }
        };
        
        waitAndAppend();
    });
}

// 获取编解码器信息
async function getCodecs() {
    try {
        const res = await fetch(BASE_URL + 'meta.json');
        if (!res.ok) {
            addStatus(`meta.json 加载失败: HTTP ${res.status}`, 'warning');
            return { videoCodec: 'avc1.42E01E', audioCodec: 'mp4a.40.2' };
        }
        metaInfo = await res.json();
        addStatus(`编解码器: 视频=${metaInfo.videoCodec}, 音频=${metaInfo.audioCodec}`, 'debug');
        return {
            videoCodec: metaInfo.videoCodec || 'avc1.42E01E',
            audioCodec: metaInfo.audioCodec || 'mp4a.40.2'
        };
    } catch (e) {
        addStatus(`获取编解码器失败: ${e.message}`, 'warning');
        return { videoCodec: 'avc1.42E01E', audioCodec: 'mp4a.40.2' };
    }
}

// 加载初始化片段（分开模式）
async function loadInitSeparate(codecs) {
    addStatus('开始加载初始化片段...', 'info');
    
    try {
        // 加载音频初始化
        const audioRes = await fetch(BASE_URL + 'audio_init.mp4');
        if (!audioRes.ok) throw new Error(`音频初始化文件加载失败: HTTP ${audioRes.status}`);
        const audioInit = await audioRes.arrayBuffer();
        addStatus(`音频初始化数据: ${audioInit.byteLength} bytes`, 'debug');
        
        // 加载视频初始化
        const videoRes = await fetch(BASE_URL + 'video_init.mp4');
        if (!videoRes.ok) throw new Error(`视频初始化文件加载失败: HTTP ${videoRes.status}`);
        const videoInit = await videoRes.arrayBuffer();
        addStatus(`视频初始化数据: ${videoInit.byteLength} bytes`, 'debug');
        
        // 创建 SourceBuffer
        audioSourceBuffer = mediaSource.addSourceBuffer(`audio/mp4; codecs="${codecs.audioCodec.toLowerCase()}"`);
        audioSourceBuffer.mode = 'sequence';
        
        videoSourceBuffer = mediaSource.addSourceBuffer(`video/mp4; codecs="${codecs.videoCodec.toLowerCase()}"`);
        videoSourceBuffer.mode = 'sequence';
        
        addStatus('SourceBuffer 创建成功', 'info');
        
        // 追加初始化数据
        await appendBuffer(audioSourceBuffer, audioInit, '音频初始化');
        await appendBuffer(videoSourceBuffer, videoInit, '视频初始化');
        
        addStatus('初始化完成', 'info');
        
    } catch (e) {
        addStatus(`加载初始化片段失败: ${e.message}`, 'error');
        throw e;
    }
}

// 加载初始化片段（合并模式）
async function loadInitMerged(codecs) {
    addStatus('开始加载初始化片段...', 'info');
    
    try {
        const res = await fetch(BASE_URL + 'init.mp4');
        if (!res.ok) throw new Error(`初始化文件加载失败: HTTP ${res.status}`);
        
        const initData = await res.arrayBuffer();
        addStatus(`初始化数据: ${initData.byteLength} bytes`, 'debug');
        
        // 创建单个 SourceBuffer
        const codecStr = `${codecs.videoCodec.toLowerCase()},${codecs.audioCodec.toLowerCase()}`;
        audioSourceBuffer = mediaSource.addSourceBuffer(`video/mp4; codecs="${codecStr}"`);
        audioSourceBuffer.mode = 'sequence';
        videoSourceBuffer = null;
        
        addStatus(`SourceBuffer 创建成功: ${codecStr}`, 'info');
        
        await appendBuffer(audioSourceBuffer, initData, '初始化数据');
        addStatus('初始化完成', 'info');
        
    } catch (e) {
        addStatus(`加载初始化片段失败: ${e.message}`, 'error');
        throw e;
    }
}

// 加载媒体片段（分开模式）
async function loadSegmentsSeparate() {
    addStatus('开始加载媒体片段...', 'info');
    
    while (!streamEnded && mediaSource.readyState === 'open') {
        try {
            // 加载音频片段
            const audioResp = await fetch(`${BASE_URL}audio_${segmentIdx}.m4s`);
            if (audioResp.status === 404) {
                addStatus(`音频片段 ${segmentIdx} 不存在`, 'warning');
            }
            
            // 加载视频片段
            const videoResp = await fetch(`${BASE_URL}video_${segmentIdx}.m4s`);
            if (videoResp.status === 404) {
                addStatus(`视频片段 ${segmentIdx} 不存在，流可能已结束`, 'warning');
                streamEnded = true;
                break;
            }
            
            if (!videoResp.ok) throw new Error(`HTTP ${videoResp.status}`);
            
            // 并行加载数据
            const [audioData, videoData] = await Promise.all([
                audioResp.status === 200 ? audioResp.arrayBuffer() : null,
                videoResp.arrayBuffer()
            ]);
            
            // 追加数据
            if (audioData) {
                await appendBuffer(audioSourceBuffer, audioData, `音频片段 ${segmentIdx}`);
            }
            await appendBuffer(videoSourceBuffer, videoData, `视频片段 ${segmentIdx}`);
            
            segmentIdx++;
            
        } catch (e) {
            addStatus(`加载片段失败: ${e.message}`, 'error');
            streamEnded = true;
            break;
        }
    }
    
    // 结束流
    if (mediaSource.readyState === 'open') {
        try {
            mediaSource.endOfStream();
            addStatus('流媒体播放结束', 'info');
        } catch (e) {
            addStatus(`endOfStream 失败: ${e.message}`, 'warning');
        }
    }
}

// 加载媒体片段（合并模式）
async function loadSegmentsMerged() {
    addStatus('开始加载媒体片段...', 'info');
    
    while (!streamEnded && mediaSource.readyState === 'open') {
        try {
            const resp = await fetch(`${BASE_URL}segment_${segmentIdx}.m4s`);
            
            if (resp.status === 404) {
                addStatus(`片段 ${segmentIdx} 不存在，流可能已结束`, 'warning');
                streamEnded = true;
                break;
            }
            
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            
            const data = await resp.arrayBuffer();
            await appendBuffer(audioSourceBuffer, data, `片段 ${segmentIdx}`);
            segmentIdx++;
            
        } catch (e) {
            addStatus(`加载片段失败: ${e.message}`, 'error');
            streamEnded = true;
            break;
        }
    }
    
    // 结束流
    if (mediaSource.readyState === 'open') {
        try {
            mediaSource.endOfStream();
            addStatus('流媒体播放结束', 'info');
        } catch (e) {
            addStatus(`endOfStream 失败: ${e.message}`, 'warning');
        }
    }
}

// 初始化 MediaSource
async function initMediaSource() {
    // 清理旧资源
    if (mediaSource) {
        try {
            if (mediaSource.readyState === 'open') {
                mediaSource.endOfStream();
            }
        } catch (e) {}
        URL.revokeObjectURL(video.src);
    }
    
    streamEnded = false;
    segmentIdx = 1;
    audioSourceBuffer = null;
    videoSourceBuffer = null;
    
    // 重置 UI
    document.getElementById('playBtn').disabled = true;
    document.getElementById('pauseBtn').disabled = true;
    statusDiv.innerHTML = `<div class="status-info">[初始化] 正在准备播放器... (模式: ${currentMode === 'separate' ? '分开音视频' : '合并'})</div>`;
    
    try {
        mediaSource = new MediaSource();
        video.src = URL.createObjectURL(mediaSource);
        
        mediaSource.addEventListener('sourceopen', async () => {
            try {
                addStatus('MediaSource 已打开', 'debug');
                
                // 获取编解码器信息
                const codecs = await getCodecs();
                
                // 根据模式加载初始化片段
                if (currentMode === 'separate') {
                    await loadInitSeparate(codecs);
                    await loadSegmentsSeparate();
                } else {
                    await loadInitMerged(codecs);
                    await loadSegmentsMerged();
                }
                
                // 更新控制按钮
                document.getElementById('playBtn').disabled = false;
                document.getElementById('pauseBtn').disabled = false;
                
            } catch (e) {
                addStatus(`初始化失败: ${e.message}`, 'error');
                console.error('MediaSource 初始化错误:', e);
            }
        });
        
        mediaSource.addEventListener('sourceclose', () => {
            addStatus('MediaSource 已关闭', 'debug');
        });
        
        mediaSource.addEventListener('error', (e) => {
            addStatus(`MediaSource 错误: ${e}`, 'error');
        });
        
    } catch (e) {
        addStatus(`创建 MediaSource 失败: ${e.message}`, 'error');
    }
}

// 切换模式
document.querySelectorAll('.mode-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        currentMode = btn.dataset.mode;
        BASE_URL = currentMode === 'separate' ? '/output_separate/' : '/output_merge/';
        
        modeInfo.textContent = currentMode === 'separate' ? '分开音视频模式' : '合并模式';
        addStatus(`切换到${currentMode === 'separate' ? '分开音视频' : '合并'}模式`, 'info');
        
        // 重新初始化播放器
        initMediaSource();
    });
});

// 事件监听
document.getElementById('playBtn').addEventListener('click', () => {
    video.play();
    document.getElementById('playBtn').disabled = true;
    document.getElementById('pauseBtn').disabled = false;
});

document.getElementById('pauseBtn').addEventListener('click', () => {
    video.pause();
    document.getElementById('pauseBtn').disabled = true;
    document.getElementById('playBtn').disabled = false;
});

document.getElementById('resetBtn').addEventListener('click', () => {
    streamEnded = true;
    initMediaSource();
});

document.getElementById('debugBtn').addEventListener('click', () => {
    isDebugMode = !isDebugMode;
    statusDiv.style.display = isDebugMode ? 'block' : 'none';
    document.getElementById('debugBtn').textContent = isDebugMode ? '隐藏调试信息' : '显示调试信息';
});

video.addEventListener('error', (e) => {
    addStatus(`视频元素错误: ${video.error?.message || e}`, 'error');
});

video.addEventListener('playing', () => {
    addStatus('视频开始播放', 'info');
});

video.addEventListener('pause', () => {
    addStatus('视频已暂停', 'info');
});

video.addEventListener('ended', () => {
    addStatus('视频播放结束', 'info');
});

// 启动播放器
initMediaSource();
</script>
</body>
</html>
```

### 说明

本项目的开发初衷是为另一个直播项目 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 提供 MP4 存储支持。

### 免责声明

- 项目中的部分代码或资料可能来源于网络，如涉及侵权，请及时联系作者删除。
- 本项目完全开源，仅供技术分享与学习交流。
- 因使用者自身行为导致的任何法律风险或商业纠纷，均与作者无关。
- 使用者应自行承担使用本项目可能带来的后果，包括但不限于版权、合规等问题。

### 联系作者

- 邮箱：2723659854@qq.com
