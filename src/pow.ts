// Proof of work for the spam protection: find a number n such that
// sha256("token:n") starts with `bits` zero bits (FetchItGuard::solves()).
// A SHA-256 of its own: crypto.subtle only exists on HTTPS pages.

const K = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
])

const encoder = new TextEncoder()

const rotr = (x: number, n: number) => (x >>> n) | (x << (32 - n))

/**
 * The SHA-256 of a string (UTF-8) as eight 32-bit words.
 */
export function sha256 (input: string): Uint32Array {
  const bytes = encoder.encode(input)
  const blocks = Math.ceil((bytes.length + 9) / 64)
  const words = new Uint32Array(blocks * 16)
  for (let i = 0; i < bytes.length; i++) {
    words[i >> 2]! |= bytes[i]! << (24 - (i % 4) * 8)
  }
  words[bytes.length >> 2]! |= 0x80 << (24 - (bytes.length % 4) * 8)
  words[words.length - 1] = bytes.length * 8

  const hash = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19])
  const w = new Uint32Array(64)

  for (let block = 0; block < blocks; block++) {
    for (let t = 0; t < 16; t++) {
      w[t] = words[block * 16 + t]!
    }
    for (let t = 16; t < 64; t++) {
      const x = w[t - 15]!
      const y = w[t - 2]!
      const s0 = rotr(x, 7) ^ rotr(x, 18) ^ (x >>> 3)
      const s1 = rotr(y, 17) ^ rotr(y, 19) ^ (y >>> 10)
      w[t] = (w[t - 16]! + s0 + w[t - 7]! + s1) | 0
    }

    let a = hash[0]!, b = hash[1]!, c = hash[2]!, d = hash[3]!
    let e = hash[4]!, f = hash[5]!, g = hash[6]!, h = hash[7]!
    for (let t = 0; t < 64; t++) {
      const t1 = (h + (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)) + ((e & f) ^ (~e & g)) + K[t]! + w[t]!) | 0
      const t2 = ((rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0
      h = g
      g = f
      f = e
      e = (d + t1) | 0
      d = c
      c = b
      b = a
      a = (t1 + t2) | 0
    }
    hash[0] = hash[0]! + a
    hash[1] = hash[1]! + b
    hash[2] = hash[2]! + c
    hash[3] = hash[3]! + d
    hash[4] = hash[4]! + e
    hash[5] = hash[5]! + f
    hash[6] = hash[6]! + g
    hash[7] = hash[7]! + h
  }

  return hash
}

/**
 * The leading zero bits of a hash.
 */
export function zeroBits (hash: Uint32Array): number {
  let bits = 0
  for (const word of hash) {
    if (word === 0) {
      bits += 32
      continue
    }
    return bits + Math.clz32(word)
  }
  return bits
}

/**
 * Find the solution for a token. It yields to the page every `batch`
 * attempts, so the page stays responsive; `signal` stops it.
 */
export async function solve (token: string, bits: number, signal?: AbortSignal, batch = 2000): Promise<string> {
  for (let n = 0; ; n++) {
    if (zeroBits(sha256(`${token}:${n}`)) >= bits) {
      return String(n)
    }
    if (n % batch === batch - 1) {
      await new Promise(resolve => setTimeout(resolve, 0))
      if (signal?.aborted) {
        throw new Error('FetchIt: the proof of work was stopped')
      }
    }
  }
}
