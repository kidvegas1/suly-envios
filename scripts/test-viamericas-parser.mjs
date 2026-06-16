import fs from 'fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const pdfPath = process.argv[2] || 'assets/DOCS/VIAMERICAS 3 MESES/DICIEMBRE 1.pdf';
const data = new Uint8Array(fs.readFileSync(pdfPath));
const pdf = await getDocument({ data, useSystemFonts: true }).promise;

function extractLines(items) {
    const yThreshold = 3;
    const lineMap = new Map();
    for (const item of items) {
        if (!item.str?.trim()) continue;
        const y = Math.round(item.transform[5]);
        let key = null;
        for (const k of lineMap.keys()) {
            if (Math.abs(k - y) <= yThreshold) { key = k; break; }
        }
        if (key === null) { key = y; lineMap.set(key, []); }
        lineMap.get(key).push(item);
    }
    return [...lineMap.entries()].sort((a, b) => b[0] - a[0]).map(([_, rowItems]) => {
        const sorted = rowItems.sort((a, b) => a.transform[4] - b.transform[4]);
        let result = '';
        for (let i = 0; i < sorted.length; i++) {
            const item = sorted[i];
            if (i > 0) {
                const prev = sorted[i - 1];
                const gap = item.transform[4] - (prev.transform[4] + (prev.width || 0));
                result += gap > 50 ? '\t' : ' ';
            }
            result += item.str;
        }
        return result.trim();
    }).filter(Boolean);
}

function parseMoney(s) {
    return parseFloat(String(s).replace(/[$,\s]/g, '')) || 0;
}

function parseViamericasViaRemoteRows(lines) {
    const result = { transactions: [], agency_number: '' };
    const rowStartRe = /^A\d{4,6}\s*-\s*(.+)$/;
    const continuationRe = /^(\d{1,4})\s+(.*)$/;
    const skipLineRe = /^(Inicio |Buscar |Producto:|Money Transfer|Nro\.|Transacci|Status|Procesar|Cheques|Reportes|Buscar nombre|\$[\d,]+||%)/i;
    const uiNoiseRe = /(Imprimir|Modificar|Cancelar|Ver más|Ya Modificada|Modificación|no disponible||||)/gi;
    const currRe = /\b(USD|MXN|GTQ|HNL|COP|NIO|BRL|PEN|DOP)\b/gi;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line || skipLineRe.test(line)) continue;
        const startMatch = line.match(rowStartRe);
        if (!startMatch) continue;
        const agencyPrefix = line.match(/^(A\d{4,6})/)[1];
        let body = startMatch[1].trim();
        if (!/\d{1,2}\/\d{1,2}\//.test(body) && !body.includes('\t') && !/[\d,]+\.\d{2}/.test(body)) continue;

        let continuation = '';
        if (i + 1 < lines.length) {
            const next = lines[i + 1].trim();
            if (continuationRe.test(next)) {
                continuation = next;
                i++;
            }
        }
        if (!continuation) continue;

        const contMatch = continuation.match(continuationRe);
        const reference = `${agencyPrefix}-${contMatch[1]}`;
        let contRest = contMatch[2].replace(uiNoiseRe, '').split('\t')[0].trim();

        let namePart1 = body;
        let dataPart = '';
        if (body.includes('\t')) {
            const tabParts = body.split('\t');
            namePart1 = tabParts[0].trim();
            dataPart = tabParts.slice(1).join(' ').trim();
        } else {
            const dateIdx = body.search(/\d{1,2}\/\d{1,2}\/\d{2,4}/);
            if (dateIdx > 0) {
                namePart1 = body.substring(0, dateIdx).trim();
                dataPart = body.substring(dateIdx).trim();
            } else dataPart = body;
        }

        dataPart = dataPart.replace(uiNoiseRe, '').trim();
        const amounts = dataPart.match(/([\d,]+\.\d{2})/g) || [];
        const principal = amounts[0] ? parseMoney(amounts[0]) : 0;
        const receivedAmt = amounts[1] ? parseMoney(amounts[1]) : 0;
        if (principal === 0 && receivedAmt === 0) continue;

        if (!result.agency_number) result.agency_number = agencyPrefix;
        result.transactions.push({ reference, principal, customer: namePart1 });
    }
    return result;
}

const allLines = [];
for (let p = 1; p <= pdf.numPages; p++) {
    const items = (await pdf.getPage(p).then((pg) => pg.getTextContent())).items;
    allLines.push(...extractLines(items));
}

const parsed = parseViamericasViaRemoteRows(allLines);
console.log('FILE:', pdfPath);
console.log('LINES:', allLines.length, 'TXNS:', parsed.transactions.length, 'AGENCY:', parsed.agency_number);
console.log('SUM:', parsed.transactions.reduce((s, t) => s + t.principal, 0).toFixed(2));
console.log('SAMPLE:', parsed.transactions.slice(0, 3));
