const fs = require('fs');
const test = require('node:test');
const assert = require('node:assert');

// Extract hashSeed function from index.html
const html = fs.readFileSync('index.html', 'utf8');
const match = html.match(/(function hashSeed.*?return h % 10000; \})/);

if (!match) {
    console.error('hashSeed function not found in index.html');
    process.exit(1);
}

const hashSeedCode = match[1];
const hashSeed = eval(`(${hashSeedCode})`);

test('hashSeed functionality', async (t) => {

    await t.test('returns 0 for empty string', () => {
        assert.strictEqual(hashSeed(""), 0);
    });

    await t.test('handles single character', () => {
        assert.strictEqual(hashSeed("a"), 97);
    });

    await t.test('handles common strings consistently', () => {
        assert.strictEqual(hashSeed("hello"), 2322);
    });

    await t.test('is deterministic for the same input', () => {
        const input = "testString123!@#";
        const result1 = hashSeed(input);
        const result2 = hashSeed(input);
        assert.strictEqual(result1, result2);
    });

    await t.test('handles long strings correctly (wraps around 32-bit unsigned int)', () => {
        const longString = "A".repeat(100);
        const result = hashSeed(longString);
        assert.ok(result >= 0 && result < 10000);
        assert.strictEqual(Number.isInteger(result), true);
    });

    await t.test('output is always within 0-9999 range', () => {
        for (let i = 0; i < 1000; i++) {
            const randomString = Math.random().toString(36).substring(2);
            const result = hashSeed(randomString);
            assert.ok(result >= 0 && result < 10000);
        }
    });

});
