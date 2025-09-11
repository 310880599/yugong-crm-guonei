class TableExporter {
    constructor() {
        this.xlsx = window.XLSX;
        if (!this.xlsx) {
            console.error('XLSX library not loaded. Please include xlsx-js-style library');
        }
    }

    // 导出所有表格
    exportAllTables(selector, options = {}) {
        const tables = document.querySelectorAll(selector);
        if (tables.length === 0) {
            console.warn('No tables found with selector:', selector);
            return;
        }

        const wb = this.xlsx.utils.book_new();
        const fileName = options.fileName || '导出数据_' + new Date().toLocaleDateString();
        const includeStyles = options.includeStyles || false;

        tables.forEach((table, index) => {
            let sheetName = options.sheetNames ? options.sheetNames[index] : `表格${index + 1}`;

            // 如果表格有标题，使用标题作为sheet名称
            const header = table.closest('.team-table')?.querySelector('.team-table-header');
            if (header) {
                sheetName = header.textContent.trim();
            }

            // 限制sheet名称长度
            sheetName = sheetName.substring(0, 31);

            let ws;
            if (includeStyles) {
                ws = this.tableToSheetWithStyles(table);
            } else {
                ws = this.xlsx.utils.table_to_sheet(table);
            }

            this.xlsx.utils.book_append_sheet(wb, ws, sheetName);
        });

        this.xlsx.writeFile(wb, fileName + '.xlsx');
    }

    // 导出所有表格到单个工作表
      exportToSingleSheet(selector, options = {}) {
        const tables = document.querySelectorAll(selector);
        if (tables.length === 0) {
            console.warn('No tables found with selector:', selector);
            return;
        }

        const wb = this.xlsx.utils.book_new();
        const fileName = options.fileName || '导出数据_' + new Date().toLocaleDateString();
        const includeStyles = options.includeStyles || false;
        const sheetName = options.sheetName || '汇总数据';

        // 合并所有表格数据，保持HTML结构
        const mergedData = [];
        const mergedStyles = [];
        const merges = []; // 存储合并单元格信息
        let currentRow = 0;

        tables.forEach((table, tableIndex) => {
            // 获取表格标题
            const teamHeader = table.closest('.team-table')?.querySelector('.team-table-header');
            if (teamHeader) {
                // 如果不是第一个表格，在表格之间添加空行分隔
                if (tableIndex > 0) {
                    mergedData.push(['']);
                    mergedStyles.push([{}]);
                    currentRow++;
                }

                // 添加表格标题（团队名称）
                mergedData.push([teamHeader.textContent.trim()]);
                const headerStyle = this.getElementStyle(teamHeader);
                headerStyle.font = headerStyle.font || {};
                headerStyle.font.bold = true;
                headerStyle.font.sz = 14;
                mergedStyles.push([headerStyle]);

                // 获取当前表格的实际列数
                const firstRow = table.querySelector('tr');
                const tableCols = firstRow ? firstRow.querySelectorAll('th, td').length : 1;
                
                // 合并标题行，使用当前表格的实际列数
                merges.push({
                    s: { r: currentRow, c: 0 },
                    e: { r: currentRow, c: Math.max(tableCols - 1, 0) }
                });
                currentRow++;
            }

            // 获取表格数据，保持HTML结构
            const rows = table.querySelectorAll('tr');
            rows.forEach((row, rowIndex) => {
                const rowData = [];
                const rowStyles = [];

                const cells = row.querySelectorAll('th, td');
                cells.forEach((cell, cellIndex) => {
                    const text = cell.textContent.trim();
                    rowData.push(text);

                    if (includeStyles) {
                        // 获取元素的计算样式，确保与HTML一致
                        const cellStyle = this.getElementStyle(cell);
                        rowStyles.push(cellStyle);
                    } else {
                        rowStyles.push({});
                    }
                });

                mergedData.push(rowData);
                mergedStyles.push(rowStyles);
                currentRow++;
            });
        });

        // 创建工作表
        const ws = this.xlsx.utils.aoa_to_sheet(mergedData);

        // 设置合并单元格
        if (merges.length > 0) {
            ws['!merges'] = merges;
        }

        // 应用样式
        if (includeStyles) {
            this.applyXlsxJsStyles(ws, mergedStyles);
        }

        // 设置列宽，根据内容调整
        if (!ws['!cols']) {
            ws['!cols'] = [];
        }

        for (let col = 0; col < mergedData[0]?.length || 0; col++) {
            let maxWidth = 15;
            for (let row = 0; row < mergedData.length; row++) {
                const cellLength = mergedData[row][col]?.length || 0;
                maxWidth = Math.max(maxWidth, Math.min(cellLength + 2, 50));
            }
            ws['!cols'][col] = { wch: maxWidth };
        }

        this.xlsx.utils.book_append_sheet(wb, ws, sheetName);
        this.xlsx.writeFile(wb, fileName + '.xlsx');
    }
    // 获取元素的样式
    getElementStyle(element) {
        const computedStyle = window.getComputedStyle(element);

        // 处理背景颜色，确保HSL颜色正确转换
        const bgColor = this.rgbToHex(computedStyle.backgroundColor);

        return {
            // 填充样式
            fill: {
                patternType: 'solid',
                fgColor: { rgb: bgColor }
            },
            // 字体样式
            font: {
                name: computedStyle.fontFamily.replace(/['"]+/g, '').split(',')[0],
                sz: Math.round(parseInt(computedStyle.fontSize) * 0.75),
                color: { rgb: this.rgbToHex(computedStyle.color) },
                bold: computedStyle.fontWeight === 'bold' || parseInt(computedStyle.fontWeight) >= 700,
                italic: computedStyle.fontStyle === 'italic',
                underline: computedStyle.textDecoration.includes('underline')
            },
            // 对齐样式
            alignment: {
                horizontal: computedStyle.textAlign === 'center' ? 'center' :
                    computedStyle.textAlign === 'right' ? 'right' : 'left',
                vertical: 'center',
                wrapText: true
            },
            // 边框样式
            border: {
                top: { style: 'thin', color: { rgb: '000000' } },
                right: { style: 'thin', color: { rgb: '000000' } },
                bottom: { style: 'thin', color: { rgb: '000000' } },
                left: { style: 'thin', color: { rgb: '000000' } }
            }
        };
    }

    // 带样式的表格转sheet
    tableToSheetWithStyles(table) {
        // 创建工作表数据
        const ws_data = [];
        const cellStyles = [];

        // 获取所有行
        const rows = table.querySelectorAll('tr');

        rows.forEach((row, rowIndex) => {
            const rowData = [];
            const rowCellStyles = [];

            // 获取行中的所有单元格
            const cells = row.querySelectorAll('th, td');

            cells.forEach((cell, cellIndex) => {
                // 获取单元格文本内容
                const text = cell.textContent.trim();
                rowData.push(text);

                // 获取单元格样式
                const computedStyle = window.getComputedStyle(cell);

                // 创建xlsx-js-style兼容的样式对象
                const cellStyle = {
                    // 填充样式
                    fill: {
                        patternType: 'solid',
                        fgColor: { rgb: this.rgbToHex(computedStyle.backgroundColor) }
                    },
                    // 字体样式
                    font: {
                        name: computedStyle.fontFamily.replace(/['"]+/g, ''),
                        sz: Math.round(parseInt(computedStyle.fontSize) * 0.75), // 转换为Excel字体大小
                        color: { rgb: this.rgbToHex(computedStyle.color) },
                        bold: computedStyle.fontWeight === 'bold' || parseInt(computedStyle.fontWeight) >= 700,
                        italic: computedStyle.fontStyle === 'italic',
                        underline: computedStyle.textDecoration.includes('underline')
                    },
                    // 对齐样式
                    alignment: {
                        horizontal: computedStyle.textAlign === 'center' ? 'center' :
                            computedStyle.textAlign === 'right' ? 'right' : 'left',
                        vertical: 'center',
                        wrapText: true
                    },
                    // 边框样式
                    border: {
                        top: { style: 'thin', color: { rgb: '000000' } },
                        right: { style: 'thin', color: { rgb: '000000' } },
                        bottom: { style: 'thin', color: { rgb: '000000' } },
                        left: { style: 'thin', color: { rgb: '000000' } }
                    }
                };

                rowCellStyles.push(cellStyle);
            });

            ws_data.push(rowData);
            cellStyles.push(rowCellStyles);
        });

        // 创建工作表
        const ws = this.xlsx.utils.aoa_to_sheet(ws_data);

        // 应用样式到xlsx-js-style
        this.applyXlsxJsStyles(ws, cellStyles);

        // 设置列宽
        if (!ws['!cols']) {
            ws['!cols'] = [];
        }

        // 根据内容设置列宽
        for (let col = 0; col < ws_data[0]?.length || 0; col++) {
            let maxWidth = 15; // 默认宽度
            for (let row = 0; row < ws_data.length; row++) {
                const cellLength = ws_data[row][col]?.length || 0;
                maxWidth = Math.max(maxWidth, Math.min(cellLength + 2, 50));
            }
            ws['!cols'][col] = { wch: maxWidth };
        }

        return ws;
    }

    // 应用xlsx-js-style样式
    applyXlsxJsStyles(ws, cellStyles) {
        // 为每个单元格应用样式
        for (let row = 0; row < cellStyles.length; row++) {
            for (let col = 0; col < cellStyles[row].length; col++) {
                const cellAddress = this.xlsx.utils.encode_cell({ r: row, c: col });
                if (!ws[cellAddress]) {
                    ws[cellAddress] = { t: 's', v: '' };
                }

                // 应用xlsx-js-style样式
                ws[cellAddress].s = cellStyles[row][col];
            }
        }
    }

    // RGB颜色转HEX
    rgbToHex(rgb) {
        if (!rgb || rgb === 'rgba(0, 0, 0, 0)' || rgb === 'transparent') {
            return 'FFFFFF';
        }

        // 处理rgb(r, g, b)格式
        const match = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
        if (match) {
            const r = parseInt(match[1]).toString(16).padStart(2, '0');
            const g = parseInt(match[2]).toString(16).padStart(2, '0');
            const b = parseInt(match[3]).toString(16).padStart(2, '0');
            return (r + g + b).toUpperCase();
        }

        // 处理rgba(r, g, b, a)格式
        const rgbaMatch = rgb.match(/rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)/);
        if (rgbaMatch) {
            const r = parseInt(rgbaMatch[1]).toString(16).padStart(2, '0');
            const g = parseInt(rgbaMatch[2]).toString(16).padStart(2, '0');
            const b = parseInt(rgbaMatch[3]).toString(16).padStart(2, '0');
            return (r + g + b).toUpperCase();
        }

        // 处理十六进制格式
        if (rgb.startsWith('#')) {
            return rgb.substring(1).toUpperCase();
        }

        // 处理HSL颜色格式
        const hslMatch = rgb.match(/hsl\((\d+),\s*(\d+)%,\s*(\d+)%\)/);
        if (hslMatch) {
            // 简单的HSL到RGB转换
            const h = parseInt(hslMatch[1]) / 360;
            const s = parseInt(hslMatch[2]) / 100;
            const l = parseInt(hslMatch[3]) / 100;

            const rgb = this.hslToRgb(h, s, l);
            const r = rgb[0].toString(16).padStart(2, '0');
            const g = rgb[1].toString(16).padStart(2, '0');
            const b = rgb[2].toString(16).padStart(2, '0');
            return (r + g + b).toUpperCase();
        }

        return 'FFFFFF';
    }

    // HSL转RGB
    hslToRgb(h, s, l) {
        let r, g, b;

        if (s === 0) {
            r = g = b = l;
        } else {
            const hue2rgb = (p, q, t) => {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1 / 6) return p + (q - p) * 6 * t;
                if (t < 1 / 2) return q;
                if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
                return p;
            };

            const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            const p = 2 * l - q;
            r = hue2rgb(p, q, h + 1 / 3);
            g = hue2rgb(p, q, h);
            b = hue2rgb(p, q, h - 1 / 3);
        }

        return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
    }
}

// 创建全局实例
const tableExporter = new TableExporter();