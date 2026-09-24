import { describe, expect, it } from 'vitest'
import { createHash } from 'node:crypto'
import { sha256, solve, zeroBits } from '../../src/pow'

const hex = (words: Uint32Array) => Array.from(words, word => word.toString(16).padStart(8, '0')).join('')

describe('sha256', () => {
  it('matches the NIST test vectors', () => {
    expect(hex(sha256(''))).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
    expect(hex(sha256('abc'))).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
    expect(hex(sha256('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq')))
      .toBe('248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1')
  })

  it('matches Node for tokens of every length around the block edges', () => {
    for (let length = 0; length < 140; length++) {
      const input = 'x'.repeat(length) + ':42'
      expect(hex(sha256(input))).toBe(createHash('sha256').update(input).digest('hex'))
    }
  })

  it('hashes UTF-8', () => {
    expect(hex(sha256('Привет'))).toBe(createHash('sha256').update('Привет').digest('hex'))
  })
})

describe('proof of work', () => {
  it('counts the leading zero bits', () => {
    expect(zeroBits(new Uint32Array([0x5f36efce, 0]))).toBe(1)
    expect(zeroBits(new Uint32Array([0, 0x0000ffff]))).toBe(48)
    expect(zeroBits(new Uint32Array([0x80000000]))).toBe(0)
  })

  it('finds the same solution the server accepts', async () => {
    // FetchItGuard::solves(): sha256("token:n") starts with `bits` zero bits.
    const token = '01234567.1700000000.0123456789abcdef.' + 'ab'.repeat(32)
    const solution = await solve(token, 12)

    const digest = createHash('sha256').update(`${token}:${solution}`).digest('hex')
    expect(digest.startsWith('000')).toBe(true)
    // The first one: every smaller n fails.
    for (let n = 0; n < Number(solution); n++) {
      expect(zeroBits(sha256(`${token}:${n}`))).toBeLessThan(12)
    }
  })

  it('can be stopped', async () => {
    const controller = new AbortController()
    controller.abort()

    await expect(solve('token', 30, controller.signal, 10)).rejects.toThrow('stopped')
  })
})
