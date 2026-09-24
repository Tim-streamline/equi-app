import http from 'node:http';
import https from 'node:https';
import { createReadStream, existsSync } from 'node:fs';
import { stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream';

const root = fileURLToPath(new URL('../', import.meta.url));
if (existsSync(path.join(root, '.env'))) process.loadEnvFile(path.join(root, '.env'));
const dev = process.argv.includes('--dev');
const port = Number(process.env.PORT ?? 3000);
const host = process.env.HOST ?? '127.0.0.1';
const backend = new URL(process.env.BACKEND_URL ?? 'http://127.0.0.1:81');
const powersync = new URL(process.env.POWERSYNC_URL ?? 'http://127.0.0.1:8080');
const metroPort = Number(process.env.METRO_PORT ?? 8082);
const metro = new URL(`http://127.0.0.1:${metroPort}`);
const dist = path.join(root, 'dist');
if (!dev && !existsSync(path.join(dist, 'index.html'))) throw new Error('Run npm run build before npm run preview.');
const child = dev ? spawn(process.execPath, ['node_modules/expo/bin/cli', 'start', '--web', '--port', String(metroPort)], { cwd: root, env: { ...process.env, BROWSER: 'none' }, stdio: 'inherit' }) : null;

function upstreamUrl(target, requestPath) {
  const url = new URL(target);
  const separator = requestPath.indexOf('?');
  url.pathname = separator < 0 ? requestPath : requestPath.slice(0, separator);
  url.search = separator < 0 ? '' : requestPath.slice(separator);
  return url;
}
function proxy(req, res, target, requestPath = req.url) {
  const transport = target.protocol === 'https:' ? https : http;
  const headers = { ...req.headers, host: target.host };
  // Preserve the browser-facing host for framework-generated URLs.
  headers['x-forwarded-host'] = req.headers.host;
  const upstream = transport.request(upstreamUrl(target, requestPath), { method: req.method, headers }, response => {
    res.writeHead(response.statusCode ?? 502, response.headers);
    pipeline(response, res, () => {});
  });
  upstream.on('error', () => { if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'text/plain' }); res.end('Service unavailable. Please try again.'); });
  res.on('close', () => upstream.destroy());
  pipeline(req, upstream, () => {});
}
const mime = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.wasm': 'application/wasm', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.ttf': 'font/ttf', '.woff2': 'font/woff2' };
const server = http.createServer(async (req, res) => {
  const pathname = new URL(req.url, 'http://localhost').pathname;
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'same-origin');
  if (/^\/(api|web-session|storage)(\/|$)/.test(pathname)) return proxy(req, res, backend);
  if (pathname.startsWith('/powersync/') && !pathname.startsWith('/powersync/worker/') && !pathname.endsWith('.wasm')) {
    return proxy(req, res, powersync, req.url.replace(/^\/powersync/, '') || '/');
  }
  if (dev) return proxy(req, res, metro);
  let file;
  try { file = path.resolve(dist, '.' + decodeURIComponent(pathname)); }
  catch { res.writeHead(400); res.end(); return; }
  if (!file.startsWith(dist + path.sep) && file !== dist) { res.writeHead(403); res.end(); return; }
  const info = await stat(file).catch(() => null);
  if (!info?.isFile()) {
    if (path.extname(pathname)) { res.writeHead(404); res.end(); return; }
    file = path.join(dist, 'index.html');
  }
  res.setHeader('Content-Type', mime[path.extname(file)] ?? 'application/octet-stream');
  res.setHeader('Cache-Control', file.endsWith('.html') ? 'no-cache' : 'public, max-age=3600');
  pipeline(createReadStream(file), res, () => {});
});
// PowerSync streams and Expo's development websocket (Fast Refresh).
server.on('upgrade', (req, socket, head) => {
  const sync = req.url.startsWith('/powersync/');
  if (!sync && !dev) return socket.destroy();
  const target = sync ? powersync : metro;
  const requestPath = sync ? req.url.replace(/^\/powersync/, '') : req.url;
  const transport = target.protocol === 'https:' ? https : http;
  const upstream = transport.request(upstreamUrl(target, requestPath), { headers: { ...req.headers, host: target.host } });
  upstream.on('upgrade', (response, remote, remoteHead) => {
    socket.write(`HTTP/1.1 101 Switching Protocols\r\n${Object.entries(response.headers).map(([key, value]) => `${key}: ${value}`).join('\r\n')}\r\n\r\n`);
    if (head.length) remote.write(head);
    if (remoteHead.length) socket.write(remoteHead);
    socket.pipe(remote).pipe(socket);
    socket.on('error', () => remote.destroy()); remote.on('error', () => socket.destroy());
  });
  upstream.on('response', response => { response.resume(); socket.destroy(); });
  upstream.on('error', () => socket.destroy()); upstream.end();
});
server.listen(port, host, () => console.log(`EquiNova web: http://${host}:${port}`));
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { child?.kill(signal); server.close(); process.exit(); });
