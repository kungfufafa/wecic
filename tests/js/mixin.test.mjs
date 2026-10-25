import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mixFeatures } from '../../resources/js/lib/mixin.js';

class Base {
    constructor() {
        this.count = 1;
    }

    init() {
        return 'init';
    }
}

describe('mixFeatures', () => {
    it('gives the target every method of every feature, run against the target\'s own state', () => {
        class Counter {
            increment() {
                this.count += 1;

                return this.count;
            }
        }

        class Greeter {
            greet(name) {
                return `Halo, ${name} (${this.count})`;
            }
        }

        const Target = mixFeatures(class extends Base {}, Counter, Greeter);
        const target = new Target();

        assert.equal(target.increment(), 2);
        assert.equal(target.greet('Ani'), 'Halo, Ani (2)');
        assert.equal(target.init(), 'init');
        assert.equal(Target.prototype.increment, Counter.prototype.increment);
    });

    it('keeps accessors and async methods as they were defined', async () => {
        class Feature {
            get double() {
                return this.count * 2;
            }

            async later() {
                return this.count;
            }
        }

        const Target = mixFeatures(class extends Base {}, Feature);
        const target = new Target();

        assert.equal(target.double, 2);
        assert.equal(await target.later(), 1);
        assert.equal(typeof Object.getOwnPropertyDescriptor(Target.prototype, 'double').get, 'function');
    });

    it('never copies a feature\'s constructor', () => {
        class Feature {
            constructor() {
                throw new Error('a feature is never constructed');
            }

            ping() {
                return 'pong';
            }
        }

        const Target = mixFeatures(class extends Base {}, Feature);

        assert.equal(new Target().ping(), 'pong');
        assert.equal(Target.prototype.constructor, Target);
    });

    it('refuses a name the target already has', () => {
        class Target {
            init() {}
        }

        class Clash {
            init() {}
        }

        assert.throws(() => mixFeatures(Target, Clash), /Target already defines init\(\) \(again in Clash\)/);
    });

    it('refuses a name two features both define, instead of letting the later one win', () => {
        class First {
            render() {
                return 'first';
            }
        }

        class Second {
            render() {
                return 'second';
            }
        }

        class Target {}

        assert.throws(() => mixFeatures(Target, First, Second), /already defines render\(\) \(again in Second\)/);
        assert.equal(new Target().render(), 'first');
    });
});
