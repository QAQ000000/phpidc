import RFB from '/vendor/novnc/core/rfb.js';

const status = document.getElementById('vnc-status');
const disconnect = document.getElementById('disconnect');
const cad = document.getElementById('send-cad');
let rfb;
try {
    const encoded = window.location.hash.slice(1);
    // Remove the bearer connection from visible history before opening the socket.
    history.replaceState(null, '', window.location.pathname + window.location.search);
    const digest = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(encoded))))
        .map((byte) => byte.toString(16).padStart(2, '0')).join('');
    if (digest !== document.body.dataset.fingerprint) throw new Error('连接信息缺失或校验失败，请返回服务重新打开 VNC。');
    const data = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(encoded), (character) => character.charCodeAt(0))));
    const socket = new URL(data.url);
    if (socket.protocol !== 'wss:' || socket.username || socket.password || socket.hash || typeof data.password !== 'string') {
        throw new Error('控制台连接格式无效。');
    }
    status.textContent = '正在直接连接远端 WSS…';
    rfb = new RFB(document.getElementById('screen'), socket.href, { credentials: { password: data.password } });
    rfb.scaleViewport = true;
    rfb.resizeSession = false;
    rfb.addEventListener('connect', () => { status.textContent = '已直连远端控制台'; cad.disabled = false; });
    rfb.addEventListener('disconnect', () => { status.textContent = '连接已断开；如未成功连接，请检查远端证书、网络或会话有效期。'; cad.disabled = true; });
    rfb.addEventListener('securityfailure', () => { status.textContent = '远端控制台认证失败，请返回服务重新打开。'; });
    rfb.addEventListener('credentialsrequired', () => { status.textContent = '远端要求额外认证，当前协议不支持，请联系管理员。'; rfb.disconnect(); });
} catch (error) {
    status.textContent = error instanceof Error ? error.message : '控制台连接失败。';
}
disconnect.addEventListener('click', () => rfb?.disconnect());
cad.addEventListener('click', () => { if (window.confirm('确认向机器发送 Ctrl+Alt+Del？可能触发系统重启。')) rfb?.sendCtrlAltDel(); });
