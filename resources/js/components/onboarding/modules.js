/**
 * Module selection rules mirrored from the server (ModuleManager): a module brings its
 * dependencies, and engine-required modules cannot be switched off.
 */

export function indexByCode(modules) {
    return Object.fromEntries(modules.map((module) => [module.code, module]));
}

export function withDependencies(codes, modulesByCode) {
    const result = new Set();
    const visit = (code) => {
        if (result.has(code) || !modulesByCode[code]) {
            return;
        }
        result.add(code);
        modulesByCode[code].depends_on.forEach(visit);
    };
    codes.forEach(visit);

    return Object.keys(modulesByCode).filter((code) => result.has(code));
}

/**
 * Why each locked module cannot be unticked: { code: reason }.
 */
export function lockedModules(selected, required, modulesByCode) {
    const locked = {};

    required.forEach((code) => {
        locked[code] = 'Needed by the core features of your business type';
    });

    selected.forEach((code) => {
        (modulesByCode[code]?.depends_on ?? []).forEach((dependency) => {
            if (!locked[dependency]) {
                const dependents = selected.filter((other) => modulesByCode[other]?.depends_on.includes(dependency));
                locked[dependency] = `Needed by ${dependents.map((other) => modulesByCode[other].name).join(', ')}`;
            }
        });
    });

    return locked;
}
