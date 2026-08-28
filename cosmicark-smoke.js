const fs = require('fs');
const vm = require('vm');

class FakeClassList {
    add() {}
    remove() {}
    toggle() {}
}

class FakeElement {
    constructor(id) {
        this.id = id;
        this.value = '';
        this.textContent = '';
        this.innerHTML = '';
        this.style = {};
        this.classList = new FakeClassList();
        this.children = [];
        this.checked = false;
        this.disabled = false;
        this.scrollHeight = 0;
        this.scrollTop = 0;
        this.clientHeight = 100;
    }
    addEventListener() {}
    setAttribute(name, value) { this[name] = value; }
    appendChild(child) { this.children.push(child); }
    querySelector() { return new FakeElement('query'); }
    closest() { return null; }
    setPointerCapture() {}
    getContext() { return drawingContext; }
}

const drawingContext = new Proxy({}, {
    get(target, key) {
        if (key === 'createLinearGradient') return () => ({addColorStop() {}});
        if (key === 'measureText') return () => ({width: 10});
        if (!(key in target)) target[key] = () => {};
        return target[key];
    },
    set(target, key, value) { target[key] = value; return true; }
});

const elements = new Map();
function element(id) {
    if (!elements.has(id)) elements.set(id, new FakeElement(id));
    return elements.get(id);
}

element('solo-difficulty').value = 'regular';
element('nickname').value = 'SMOKE';
element('game').getContext = () => drawingContext;

let frameCallback = null;
const sandbox = {
    console,
    Math,
    Date,
    JSON,
    Map,
    Set,
    URLSearchParams,
    performance: {now: () => 0},
    setTimeout: () => 0,
    clearTimeout() {},
    setInterval: () => 0,
    clearInterval() {},
    requestAnimationFrame: callback => { frameCallback = callback; },
    fetch: async () => ({ok: false, json: async () => ({}), text: async () => ''}),
    localStorage: {getItem: () => null, setItem() {}},
    document: {
        getElementById: element,
        querySelectorAll: () => [],
        createElement: () => new FakeElement('created'),
        addEventListener() {},
        activeElement: null
    },
    window: {
        innerWidth: 1280,
        innerHeight: 720,
        addEventListener() {},
        AudioContext: null,
        webkitAudioContext: null
    }
};

let html = fs.readFileSync('index.html', 'utf8');
let script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
script = script.replace(/\}\)\(\);\s*$/, 'globalThis.__arkTest={startSolo,newWorld,updateWorld,startRescue,startShower,spawnMeteor,render,resizeStage,getWorld:()=>world};})();');
vm.createContext(sandbox);
vm.runInContext(script, sandbox, {filename: 'cosmicark.inline.js'});

Promise.resolve().then(() => {
    sandbox.window.innerWidth = 390;
    sandbox.window.innerHeight = 844;
    sandbox.__arkTest.resizeStage();
    const transform = element('stage').style.transform;
    const phoneScale = Number((transform.match(/scale\(([^)]+)\)/) || [])[1]);
    if (!transform.includes('rotate(90deg)') || Math.abs(phoneScale - 390 / 720) > .001) throw new Error('iPhone portrait-to-landscape scaling failed.');
    if (82 * phoneScale < 44 || 86 * phoneScale < 44 || 21 * phoneScale < 11) throw new Error('iPhone controls or text are below target size.');
    sandbox.document.activeElement = {tagName: 'INPUT'};
    sandbox.window.innerHeight = 500;
    sandbox.__arkTest.resizeStage();
    const keyboardScale = Number((element('stage').style.transform.match(/scale\(([^)]+)\)/) || [])[1]);
    if (keyboardScale !== phoneScale) throw new Error('iPhone keyboard caused the interface to shrink.');
    sandbox.document.activeElement = null;
    sandbox.window.innerWidth = 1280;
    sandbox.window.innerHeight = 720;
    sandbox.__arkTest.resizeStage();
    sandbox.__arkTest.startSolo();
    let active = sandbox.__arkTest.getWorld();
    if (!active || active.phase !== 'transition' || active.fuel !== 40) throw new Error('Solo initialization failed.');
    for (let i = 0; i < 90; i++) sandbox.__arkTest.updateWorld(1 / 30);
    active = sandbox.__arkTest.getWorld();
    if (active.phase !== 'shower') throw new Error('Meteor transition failed.');
    active.meteors = [];
    for (let i = 0; i < 40; i++) sandbox.__arkTest.spawnMeteor();
    if (!active.meteors.every(meteor =>
        (meteor.x === 640 && meteor.vx === 0 && Math.sign(meteor.vy) === Math.sign(340 - meteor.y)) ||
        (meteor.y === 340 && meteor.vy === 0 && Math.sign(meteor.vx) === Math.sign(640 - meteor.x))
    )) throw new Error('Meteor escaped the four cardinal attack lanes.');
    sandbox.__arkTest.render(3000);
    sandbox.__arkTest.startRescue();
    sandbox.__arkTest.updateWorld(1 / 30);
    active = sandbox.__arkTest.getWorld();
    if (active.phase !== 'rescue' || active.beasties.length !== 2) throw new Error('Rescue initialization failed.');
    sandbox.__arkTest.render(4000);
    if (typeof frameCallback !== 'function') throw new Error('Animation loop was not scheduled.');
    console.log('Cosmic Ark runtime smoke test OK: iPhone sizing, cardinal meteor lanes, shower, rescue, OBJ fallback renderer.');
}).catch(error => {
    console.error(error);
    process.exitCode = 1;
});
