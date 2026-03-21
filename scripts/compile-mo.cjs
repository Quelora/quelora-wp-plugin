/**
 * @file compile-mo.cjs
 * @description Build script to automate the conversion of .po files to .mo binary format.
 * It also generates the necessary JSON files for Gutenberg's i18n implementation.
 * @author Quelora Architecture Team
 */

const fs = require('fs');
const path = require('path');
const gettextParser = require('gettext-parser');

const languagesDir = path.join(__dirname, '../languages');

/**
 * Compiles .po files to .mo and generates i18n JSON for the editor.
 * * @function compileTranslations
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

        // 2. Generate the JSON file for Gutenberg (JED format)
        // This is required for the strings inside our React Sidebar to translate.
        const jedData = {
            'translation-revision-date': po.headers['po-revision-date'],
            'generator': 'Quelora i18n Compiler',
            'domain': 'quelora',
            'locale_data': {
                'quelora': {
                    '': {
                        'domain': 'quelora',
                        'lang': po.headers['language'],
                        'plural-forms': po.headers['plural-forms']
                    }
                }
            }
        };

        // Map translations to JED format
        Object.keys(po.translations['']).forEach(key => {
            if (key !== '') {
                jedData.locale_data.quelora[key] = po.translations[''][key].msgstr;
            }
        });

        const jsonFilePath = filePath.replace('.po', '-es_ES.json'); // Match WP expected naming
        fs.writeFileSync(jsonFilePath, JSON.stringify(jedData));
        console.log(`Generated Gutenberg JSON: ${path.basename(jsonFilePath)}`);
    });
}

try {
    compileTranslations();
} catch (error) {
    console.error('I18n Compilation failed:', error.message);
    process.exit(1);
}