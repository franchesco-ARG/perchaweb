const fs = require('fs');
const test = require('node:test');
const assert = require('node:assert');

const htmlContent = fs.readFileSync('index.html', 'utf8');

const regexCheckUserSession = /async function checkUserSession\(\)\s*\{[\s\S]*?render\(\);\s*\}/;
const matchCheckUserSession = htmlContent.match(regexCheckUserSession);

if (!matchCheckUserSession) {
    throw new Error('Could not find checkUserSession in index.html');
}

eval(matchCheckUserSession[0].replace('async function checkUserSession', 'global.checkUserSession = async function checkUserSession'));

test('checkUserSession handles fetch error gracefully', async (t) => {
    // Mock state
    global.state = { user: null };

    // Mock render
    let renderCalled = false;
    global.render = () => { renderCalled = true; };

    // Mock fetch to throw an error
    global.fetch = async () => {
        throw new Error('Network error');
    };

    await global.checkUserSession();

    // Check if state is untouched and render was called
    assert.strictEqual(global.state.user, null);
    assert.strictEqual(renderCalled, true);
});
