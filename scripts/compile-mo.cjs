/*
 * Quelora — quelora-wp-plugin
 * Copyright (C) 2026 Germán Zelaya — https://quelora.org
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * This file is part of Quelora. See the LICENSE file for terms.
 */

/**
 * @file compile-mo.cjs
 * @description Build script to automate the conversion of .po files to .mo binary format.
 * Generates the necessary JSON (JED) files mapped to specific WordPress script handles
 * for Gutenberg and SPA i18n implementation.
 * @author Quelora Architecture Team
 */

const fs = require('fs');
const path = require('path');
const gettextParser = require('gettext-parser');

const languagesDir = path.join(__dirname, '../languages');

/**
 * List of registered WordPress script handles that require JavaScript translations.
 * The compiler will generate a unique JED formatted JSON file for each handle.
 * @type {string[]}
 */
const scriptHandles = [
    'quelora-admin-spa',
    'quelora-sidebar'
];

/**
 * Compiles .po files to .mo and generates i18n JSON for the React scripts.
 *
 * @function compileTranslations
 * @returns {void}
 */
function compileTranslations() {
    if (!fs.existsSync(languagesDir)) {
        console.error('Error: The "languages" directory does not exist.');
        return;
    }

    const files = fs.readdirSync(languagesDir);
    const poFiles = files.filter(file => file.endsWith('.po'));

    if (poFiles.length === 0) {
        console.warn('Warning: No .po files found in the languages directory.');
        return;
    }

    poFiles.forEach(file => {
        const filePath = path.join(languagesDir, file);
        const poContent = fs.readFileSync(filePath);
        
        // Parse the .po file
        const po = gettextParser.po.parse(poContent);

        // 1. Generate the .mo binary file
        const moFilePath = filePath.replace('.po', '.mo');
        const moBuffer = gettextParser.mo.compile(po);
        fs.writeFileSync(moFilePath, moBuffer);
        console.log(`Successfully compiled: ${path.basename(moFilePath)}`);

        // 2. Generate the JSON payload for React (JED format)
        const jedData = {
            'translation-revision-date': po.headers['po-revision-date'] || '',
            'generator': 'Quelora i18n Compiler',
            'domain': 'quelora',
            'locale_data': {
                'quelora': {
                    '': {
                        'domain': 'quelora',
                        'lang': po.headers['language'] || '',
                        'plural-forms': po.headers['plural-forms'] || 'nplurals=2; plural=(n != 1);'
                    }
                }
            }
        };

        // Map translations to JED format
        if (po.translations && po.translations['']) {
            Object.keys(po.translations['']).forEach(key => {
                if (key !== '') {
                    jedData.locale_data.quelora[key] = po.translations[''][key].msgstr;
                }
            });
        }

        // The base name of the .po file, for example 'quelora-es_ES'
        const baseName = path.basename(file, '.po');

        // 3. Duplicate the JSON file for each required script handle
        scriptHandles.forEach(handle => {
            const jsonFilePath = path.join(languagesDir, `${baseName}-${handle}.json`);
            fs.writeFileSync(jsonFilePath, JSON.stringify(jedData));
            console.log(`Generated React JSON: ${path.basename(jsonFilePath)}`);
        });
    });
}

try {
    compileTranslations();
} catch (error) {
    console.error('I18n Compilation failed:', error.message);
    process.exit(1);
}