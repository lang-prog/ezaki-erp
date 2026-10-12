import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../resources/js/Pages/Company/CompanySettings.jsx', import.meta.url), 'utf8');

test('save button is a submit button inside the form wired to the save handler', () => {
    assert.match(source, /<form [^>]*onSubmit=\{submit\}/);
    assert.match(source, /<button type="submit"[^>]*disabled=\{form\.processing\}/);
});

test('submit posts the spoofed PUT through form.post without chaining transform()', () => {
    // Inertia's useForm().transform() returns undefined, so chaining it threw before any request was sent.
    assert.doesNotMatch(source, /\.transform\([^)]*\)\s*\)?\s*\.post/);
    assert.match(source, /useForm\(\{ _method: 'put'/);
    assert.match(source, /form\.post\('\/settings\/company', \{[^}]*forceFormData: true/s);
});

test('failures surface a message and success resets upload state', () => {
    assert.match(source, /requestFailed/);
    assert.match(source, /print_background: null, remove_print_background: false/);
});
