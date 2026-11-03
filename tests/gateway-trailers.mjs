import http2 from 'node:http2';
import tls from 'node:tls';
import crypto from 'node:crypto';
import fs from 'node:fs';

// Independent native-crypto signer and real trailing HEADERS frames; no tokens,
// proofs or private keys are written to the report.
const origin = 'https://api.example:9511';
const { publicKey, privateKey } = crypto.generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
const jwk = publicKey.export({ format: 'jwk' });
const b64 = value => Buffer.from(value).toString('base64url');
const proof = (method, path, nonce, access) => {
  const header = b64(JSON.stringify({ typ: 'dpop+jwt', alg: 'ES256', jwk }));
  const claims = { htm: method, htu: origin + path, iat: Math.floor(Date.now() / 1000), jti: crypto.randomBytes(16).toString('hex') };
  if (nonce) claims.nonce = nonce;
  if (access) claims.ath = crypto.createHash('sha256').update(access).digest('base64url');
  const payload = b64(JSON.stringify(claims));
  const input = header + '.' + payload;
  return input + '.' + crypto.sign('sha256', Buffer.from(input), { key: privateKey, dsaEncoding: 'ieee-p1363' }).toString('base64url');
};
const cases = [];
const check = (name, ok, details = {}) => {
  cases.push({ case: name, result: ok ? 'PASS' : 'FAIL', ...details });
  if (!ok) throw new Error('HTTP/2 wire case failed: ' + name);
};
const session = http2.connect('https://127.0.0.1:9511', {
  ca: fs.readFileSync('/workspace/build/gateway/ca.pem'), servername: 'api.example',
  checkServerIdentity: (_host, cert) => tls.checkServerIdentity('api.example', cert),
});
const request = (method, path, headers = {}, body = null, trailers = null) => new Promise((resolve, reject) => {
  const stream = session.request({ ':method': method, ':scheme': 'https', ':authority': 'api.example:9511', ':path': path, 'content-type': 'application/json', ...headers }, { endStream: false, waitForTrailers: trailers !== null });
  let responseHeaders = {}, text = '', sent = false;
  stream.setEncoding('utf8');
  stream.setTimeout(8000, () => stream.destroy(new Error('HTTP/2 wire timeout; trailersSent=' + sent)));
  stream.on('error', reject);
  stream.on('response', headers => { responseHeaders = headers; });
  stream.on('data', chunk => { text += chunk; });
  if (trailers !== null) stream.on('wantTrailers', () => { sent = true; stream.sendTrailers(trailers); });
  stream.on('end', () => {
    let data = null;
    try { data = JSON.parse(text); } catch {}
    resolve({ status: Number(responseHeaders[':status']), headers: responseHeaders, data, trailersSent: sent });
  });
  stream.end(body === null ? undefined : JSON.stringify(body));
});
const expect = (name, r, status, error) => check(name, r.status === status && (error === undefined || r.data?.error === error), { status: r.status, expected: status, error: r.data?.error ?? null });
let failure = null;
try {
  await new Promise((resolve, reject) => { session.once('connect', resolve); session.once('error', reject); });
  check('h2-tls-peer-and-alpn', session.socket.authorized && session.alpnProtocol === 'h2');
  const challenge = await request('POST', '/token', { dpop: proof('POST', '/token') }, { subject: 'fixture-h2trailer' });
  expect('h2-trailer-fixture-nonce', challenge, 400, 'use_dpop_nonce');
  const tokenNonce = challenge.headers['dpop-nonce'];
  const issued = await request('POST', '/token', { dpop: proof('POST', '/token', tokenNonce) }, { subject: 'fixture-h2trailer' });
  expect('h2-trailer-fixture-issued', issued, 200);
  const access = issued.data.access_token;
  const resourceChallenge = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: proof('POST', '/items', null, access) });
  expect('h2-trailer-resource-nonce', resourceChallenge, 401, 'use_dpop_nonce');
  const nonce = resourceChallenge.headers['dpop-nonce'];
  const resourceProof = () => proof('POST', '/items', nonce, access);
  let p = resourceProof();
  let r = await request('POST', '/items', { authorization: 'DPoP bogus', dpop: p }, null, { authorization: 'DPoP ' + access });
  expect('h2-hidden-auth-cannot-replace-invalid-header', r, 401, 'invalid_token');
  check('h2-real-auth-trailer-sent', r.trailersSent);
  r = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: p });
  expect('h2-hidden-auth-no-proof-consumption', r, 200);
  p = resourceProof();
  r = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: p }, null, { authorization: 'DPoP bogus', dpop: 'bad' });
  expect('h2-trailers-cannot-corrupt-valid-initial-fields', r, 200);
  check('h2-real-multiple-trailers-sent', r.trailersSent);
  r = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: p });
  expect('h2-streamed-success-proof-is-consumed', r, 401, 'invalid_dpop_proof');
  p = resourceProof();
  r = await request('POST', '/items', {}, null, { authorization: 'DPoP ' + access, dpop: p });
  expect('h2-trailer-only-credentials-not-authentication', r, 401);
  check('h2-trailer-only-no-spurious-token-error', r.data?.error === undefined);
  // A forbidden declaration is rejected before the body; use an ordinary
  // completed stream rather than sending frames after the peer's reset.
  r = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: p, trailer: 'Authorization' });
  expect('h2-forbidden-trailer-declaration', r, 400, 'invalid_request');
  const declarations = ['dPoP', 'X-Checksum, AUTHORIZATION'];
  for (const declaration of declarations) {
    r = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: resourceProof(), trailer: declaration });
    expect('h2-forbidden-declaration-' + declaration, r, 400, 'invalid_request');
  }
  r = await request('POST', '/items', { authorization: 'DPoP ' + access, dpop: resourceProof(), trailer: 'X-Checksum' }, null, { 'x-checksum': 'synthetic' });
  expect('h2-harmless-trailer-remains-compatible', r, 200);
  r = await request('POST', '/token', { dpop: proof('POST', '/token', tokenNonce) }, { subject: 'fixture-body-stream' }, { authorization: 'DPoP bogus', dpop: 'bad' });
  expect('h2-body-and-original-proof-preserved', r, 200);
} catch (error) { failure = error.message; process.stderr.write(failure + '\n'); process.exitCode = 1; }
finally { session.destroy(); process.stdout.write(JSON.stringify({ node: process.version, cases, failure, meaning: 'Actual HTTP/2 trailing HEADERS with independent signer; no credentials in evidence' }, null, 2) + '\n'); }
