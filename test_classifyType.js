const fs = require('fs');
const test = require('node:test');
const assert = require('node:assert');

const htmlContent = fs.readFileSync('index.html', 'utf8');

const regexCategoryToType = /const CATEGORY_TO_TYPE = \{[\s\S]*?\};/;
const matchCategoryToType = htmlContent.match(regexCategoryToType);

const regexClassifyType = /function classifyType\(title, category\)\s*\{[\s\S]*?return CATEGORY_TO_TYPE\[category\] \|\| 'tshirt';\s*\}/;
const matchClassifyType = htmlContent.match(regexClassifyType);

if (!matchCategoryToType || !matchClassifyType) {
    throw new Error('Could not find classifyType or CATEGORY_TO_TYPE in index.html');
}

// Convert const to global variable so classifyType can access it
eval(matchCategoryToType[0].replace('const CATEGORY_TO_TYPE', 'global.CATEGORY_TO_TYPE'));
eval(matchClassifyType[0].replace('function classifyType', 'global.classifyType = function classifyType'));

test('classifyType title matches', (t) => {
    // jacket
    assert.strictEqual(classifyType('campera de cuero', 'Desconocido'), 'jacket');
    assert.strictEqual(classifyType('chaqueta de cuero tipo bomber', 'Desconocido'), 'jacket');
    // skirt
    assert.strictEqual(classifyType('pollera larga', 'Desconocido'), 'skirt');
    // dress
    assert.strictEqual(classifyType('vestido de noche', 'Desconocido'), 'dress');
    // pants
    assert.strictEqual(classifyType('pantalon vaquero', 'Desconocido'), 'pants');
    assert.strictEqual(classifyType('pantalon cargo', 'Desconocido'), 'pants');
    assert.strictEqual(classifyType('corderoy azul', 'Desconocido'), 'pants');
    // vest
    assert.strictEqual(classifyType('chaleco tejido', 'Desconocido'), 'vest');
    // shoes
    assert.strictEqual(classifyType('zapatilla deportiva', 'Desconocido'), 'shoes');
    // belt
    assert.strictEqual(classifyType('cinturon negro', 'Desconocido'), 'belt');
    assert.strictEqual(classifyType('cinturón marrón', 'Desconocido'), 'belt');
    // sweater
    assert.strictEqual(classifyType('buzo con capucha', 'Desconocido'), 'sweater');
    assert.strictEqual(classifyType('sweater de lana', 'Desconocido'), 'sweater');
    assert.strictEqual(classifyType('hoodie gris', 'Desconocido'), 'sweater');
    // tshirt
    assert.strictEqual(classifyType('remera estampada', 'Desconocido'), 'tshirt');
    assert.strictEqual(classifyType('camisa a cuadros', 'Desconocido'), 'tshirt');
});

test('classifyType category fallback', (t) => {
    // Exact mapping from CATEGORY_TO_TYPE
    assert.strictEqual(classifyType('algo raro', 'Remeras'), 'tshirt');
    assert.strictEqual(classifyType('algo raro', 'Camperas'), 'jacket');
    assert.strictEqual(classifyType('algo raro', 'Pantalones'), 'pants');
    assert.strictEqual(classifyType('algo raro', 'Vestidos'), 'dress');
    assert.strictEqual(classifyType('algo raro', 'Buzos'), 'sweater');
    assert.strictEqual(classifyType('algo raro', 'Accesorios'), 'belt');
    assert.strictEqual(classifyType('algo raro', 'Calzado'), 'shoes');
});

test('classifyType ultimate fallback', (t) => {
    assert.strictEqual(classifyType('sin coincidencia', 'sin categoria'), 'tshirt');
});

test('classifyType casing', (t) => {
    assert.strictEqual(classifyType('CAMPERA DE JEAN', 'Desconocido'), 'jacket');
    assert.strictEqual(classifyType('PANTALON Cargo', 'Desconocido'), 'pants');
    assert.strictEqual(classifyType('Vestido Roto', 'Desconocido'), 'dress');
});
