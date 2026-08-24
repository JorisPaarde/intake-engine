import Alpine from 'alpinejs';
import { registerDemoGuide } from './demo-guide';

window.Alpine = Alpine;

registerDemoGuide(Alpine);

/**
 * Dutch HTML5 constraint messages (BL-071). Browser-native English
 * ("Please fill out this field.") is replaced when the document is nl.
 */
function registerDutchFormValidation() {
    const locale = (document.documentElement.lang || '').toLowerCase();
    if (!locale.startsWith('nl')) {
        return;
    }

    const messageFor = (el) => {
        if (el.validity.valueMissing) {
            return 'Vul dit veld in.';
        }
        if (el.validity.typeMismatch) {
            if (el.type === 'email') {
                return 'Vul een geldig e-mailadres in.';
            }
            return 'De invoer klopt niet.';
        }
        if (el.validity.patternMismatch) {
            return 'De invoer heeft niet het juiste formaat.';
        }
        if (el.validity.tooShort) {
            return 'De invoer is te kort.';
        }
        if (el.validity.tooLong) {
            return 'De invoer is te lang.';
        }
        if (el.validity.rangeUnderflow || el.validity.rangeOverflow) {
            return 'Kies een geldige waarde.';
        }
        return 'Controleer dit veld.';
    };

    document.addEventListener(
        'invalid',
        (event) => {
            const el = event.target;
            if (!(el instanceof HTMLElement) || !('setCustomValidity' in el)) {
                return;
            }
            el.setCustomValidity(messageFor(el));
        },
        true,
    );

    document.addEventListener(
        'input',
        (event) => {
            const el = event.target;
            if (el instanceof HTMLElement && 'setCustomValidity' in el) {
                el.setCustomValidity('');
            }
        },
        true,
    );
}

registerDutchFormValidation();

Alpine.start();
