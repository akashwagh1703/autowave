// Helpers over the builder catalogue sent by the server (AutomationCatalog::forBuilder).

export function triggerOf(catalog, key) {
    return catalog.triggers.find((trigger) => trigger.key === key) ?? null;
}

export function fieldsFor(catalog, trigger) {
    return trigger ? catalog.fields.filter((field) => trigger.entities.includes(field.entity)) : [];
}

export function actionsFor(catalog, trigger) {
    return trigger ? catalog.actions.filter((action) => action.entities.some((entity) => trigger.entities.includes(entity))) : [];
}

export function waitModesFor(catalog, trigger) {
    return catalog.waitModes.filter((mode) => !mode.subject || mode.subject === trigger?.subject);
}

export function variablesFor(catalog, trigger) {
    return catalog.variables.filter((variable) => variable.entity === 'business' || trigger?.entities.includes(variable.entity));
}

export function operatorsFor(catalog, field) {
    return field ? (catalog.operators[field.type] ?? []) : [];
}

export const VALUELESS_OPERATORS = ['is_set', 'is_not_set', 'is_true', 'is_false'];

export function defaultConfig(action, catalog) {
    switch (action) {
        case 'send_whatsapp':
            return { message: '' };
        case 'send_email':
            return { subject: '', message: '' };
        case 'send_notification':
            return { recipients: 'owners', subject: '', message: '' };
        case 'create_task':
            return { title: '', due_in_hours: 0 };
        case 'assign_lead':
            return { mode: 'auto' };
        case 'update_lead':
            return { stage: catalog.stages[0]?.value ?? '' };
        case 'update_customer':
            return { tag: '' };
        case 'ai_draft_reply':
            return { instructions: '' };
        default:
            return {};
    }
}

export function defaultRule(catalog, trigger) {
    const field = fieldsFor(catalog, trigger)[0];
    const operator = operatorsFor(catalog, field)[0]?.value ?? '';

    return { field: field?.key ?? '', operator, value: '' };
}

let sequence = 0;
export const nextKey = () => `step-${Date.now()}-${sequence++}`;

export function newStep(type, catalog, trigger) {
    if (type === 'condition') {
        return { _key: nextKey(), type, action: null, config: { match: 'all', rules: [defaultRule(catalog, trigger)] } };
    }

    if (type === 'wait') {
        return { _key: nextKey(), type, action: null, config: { mode: 'delay', amount: 1, unit: 'hours' } };
    }

    const actions = actionsFor(catalog, trigger);
    const action = (actions.find((item) => item.key === 'send_whatsapp') ?? actions[0])?.key ?? '';

    return { _key: nextKey(), type: 'action', action, config: defaultConfig(action, catalog) };
}
