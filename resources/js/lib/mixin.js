/**
 * Building one class out of feature classes, the way resources/js/app.js
 * builds PortalApp from resources/js/app/*.js. Pure, so tests/js runs it
 * under Node.
 */

/**
 * Copy every method and accessor each feature class defines onto `target`'s
 * prototype, descriptors and all. A feature holds no state of its own: its
 * methods use the fields `target`'s constructor sets. A name `target`
 * already has, or that two features both define, throws instead of
 * overriding, since a silent override would change what every caller gets.
 *
 * @template {Function} T
 * @param {T} target
 * @param {...Function} features
 * @returns {T} `target`, now with the features' methods.
 * @throws {Error} when a feature defines a name `target` already has.
 */
export function mixFeatures(target, ...features) {
    features.forEach((feature) => {
        Object.getOwnPropertyNames(feature.prototype)
            .filter((name) => name !== 'constructor')
            .forEach((name) => {
                if (Object.hasOwn(target.prototype, name)) {
                    throw new Error(`${target.name} already defines ${name}() (again in ${feature.name}).`);
                }

                Object.defineProperty(target.prototype, name, Object.getOwnPropertyDescriptor(feature.prototype, name));
            });
    });

    return target;
}
