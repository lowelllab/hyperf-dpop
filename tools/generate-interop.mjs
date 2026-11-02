import { generateKeyPairSync, createHash, sign } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

// Synthetic public verification vector. The generated private key is never exported or saved.
const { privateKey, publicKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
const jwk = publicKey.export({ format: 'jwk' })
const access = 'synthetic-access-for-interop'
const now = 1800000000
const encode = value => Buffer.from(JSON.stringify(value)).toString('base64url')
const header = { typ: 'dpop+jwt', alg: 'ES256', jwk }
const claims = { htm: 'GET', htu: 'https://api.example/items', iat: now, jti: 'node-interop-synthetic-vector', ath: createHash('sha256').update(access).digest('base64url') }
const input = `${encode(header)}.${encode(claims)}`
const proof = `${input}.${sign('sha256', Buffer.from(input), { key: privateKey, dsaEncoding: 'ieee-p1363' }).toString('base64url')}`
const jkt = createHash('sha256').update(JSON.stringify({ crv: jwk.crv, kty: jwk.kty, x: jwk.x, y: jwk.y })).digest('base64url')
const directory = fileURLToPath(new URL('../tests/Fixtures/', import.meta.url))
mkdirSync(directory, { recursive: true })
writeFileSync(`${directory}node-interop.json`, JSON.stringify({ producer: 'Node.js crypto', synthetic: true, now, access, jkt, proof }, null, 2) + '\n')
process.stdout.write('Synthetic Node ES256 public vector generated; private key discarded\n')
