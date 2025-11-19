var ExportSelectTables = (function () {

    function addCheckboxes() {
        var tables = document.querySelectorAll(".export");
        console.log('找到 ' + tables.length + ' 个需要添加复选框的表格');
        
        if (tables.length === 0) {
            console.warn('未找到 .export 类的元素，请检查 HTML 结构');
            return;
        }
        
        tables.forEach(function (card, index) {
            if (!card) {
                console.warn('表格 ' + index + ' 不存在，跳过');
                return;
            }
            
            if (card.dataset.exportCheckboxAdded) {
                console.log('表格 ' + index + ' 已添加复选框，跳过');
                return; // 避免重复添加
            }
            
            // 兼容新旧样式：优先查找新的 wukong-table-card-header，如果没有则查找 layui-card-header
            var header = null;
            try {
                header = card.querySelector(".wukong-table-card-header") || card.querySelector(".layui-card-header");
            } catch(e) {
                console.warn('查找 header 时出错:', e);
            }
            
            var labelText = "表" + (index + 1);
            
            if (header && header.nodeType === 1) { // 确保是元素节点
                try {
                    // 获取标题文本，优先从 span 或第一个文本节点获取
                    var titleSpan = header.querySelector("span");
                    if (titleSpan && titleSpan.textContent) {
                        labelText = titleSpan.textContent.trim();
                    } else if (header.childNodes && header.childNodes.length > 0) {
                        // 遍历子节点，找到第一个文本节点
                        for (var i = 0; i < header.childNodes.length; i++) {
                            var node = header.childNodes[i];
                            if (node.nodeType === 3 && node.textContent) { // 文本节点
                                var text = node.textContent.trim();
                                if (text) {
                                    labelText = text;
                                    break;
                                }
                            } else if (node.nodeType === 1 && node.textContent) { // 元素节点
                                var text = node.textContent.trim();
                                if (text) {
                                    labelText = text;
                                    break;
                                }
                            }
                        }
                    }
                } catch(e) {
                    console.warn('获取标题文本时出错:', e);
                }
            }
            
            console.log('为表格 ' + index + ' 添加复选框，标题：' + labelText);
            
            var wrapper = document.createElement("div");
            wrapper.className = "table-export-select";
            wrapper.style.margin = "8px 0";
            wrapper.style.padding = "8px 12px";
            wrapper.style.background = "#f8f9fa";
            wrapper.style.borderRadius = "6px";
            wrapper.style.border = "1px solid #e0e0e0";
            wrapper.style.display = "flex";
            wrapper.style.alignItems = "center";

            var checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.className = "table-checkbox";
            checkbox.dataset.tableIndex = index;
            checkbox.style.marginRight = "8px";
            checkbox.style.cursor = "pointer";
            checkbox.style.width = "18px";
            checkbox.style.height = "18px";

            var label = document.createElement("label");
            label.style.marginLeft = "6px";
            label.style.cursor = "pointer";
            label.style.fontWeight = "500";
            label.style.color = "#333";
            label.style.marginBottom = "0";
            label.innerText = labelText;
            label.setAttribute("for", "export-checkbox-" + index);
            checkbox.id = "export-checkbox-" + index;

            wrapper.appendChild(checkbox);
            wrapper.appendChild(label);

            // 确保有父节点
            if (card.parentNode) {
                card.parentNode.insertBefore(wrapper, card);
                card.dataset.exportCheckboxAdded = "true";
                console.log('复选框已添加到表格 ' + index);
            } else {
                console.error('表格 ' + index + ' 没有父节点，无法添加复选框');
            }
        });
        
        console.log('复选框添加完成，共处理 ' + tables.length + ' 个表格');
    }
    //请求接口
    function exportAllData(table) {
        var url = $(table).data('url');
        if (!url) {
            return Promise.resolve(null);
        }
        var dataParams = $(table).data('params');

        // 解析 data-params 参数
        var params = {};
        if (dataParams && dataParams.ele) {
            dataParams.ele.forEach(function (selector) {
                var $element = $(selector);
                if ($element.length > 0) {
                    var name = $element.attr('name');
                    var value = $element.val();
                    if (name && value) {
                        params[name] = value;
                    }
                }
            });
        }
        if (dataParams && dataParams.data) {
            params = { ...params, ...dataParams.data };
        }
        return new Promise((resolve, reject) => {
            $.ajax({
                url: url,
                type: "post",
                dataType: "json",
                data: params,
                success: function (res) {
                    if (res.code == 200) {
                        resolve(res.data);
                    } else {
                        reject(new Error(res.msg || '获取数据失败'));
                    }
                },
                error: function (xhr, status, error) {
                    reject(new Error('网络请求失败: ' + error));
                }
            });
        });
    }

    // 导出选中的表格
    async function exportSelected(fileName) {
        if (typeof XLSX === "undefined") {
            console.error("请先引入 SheetJS (xlsx.full.min.js)");
            return;
        }

        var checkboxes = document.querySelectorAll(".table-checkbox:checked");
        if (checkboxes.length === 0) {
            alert("请先选择要导出的表格");
            return;
        }

        var wb = XLSX.utils.book_new();
        var tables = document.querySelectorAll(".export table");

        try {
            for (var i = 0; i < checkboxes.length; i++) {
                var cb = checkboxes[i];
                var index = cb.dataset.tableIndex;
                var table = tables[index];
                if (!table) continue;

                // 等待异步数据返回
                var data = await exportAllData(table);

                // 兼容新旧样式：优先查找新的 wukong-table-card-header，如果没有则查找 layui-card-header
                var exportCard = table.closest(".export");
                var headerEl = exportCard ? (exportCard.querySelector(".wukong-table-card-header") || exportCard.querySelector(".layui-card-header")) : null;
                var sheetName = "表" + (i + 1);
                if (headerEl) {
                    // 获取标题文本，优先从 span 或第一个文本节点获取
                    var titleSpan = headerEl.querySelector("span");
                    if (titleSpan) {
                        sheetName = titleSpan.textContent.trim();
                    } else if (headerEl.childNodes[0]) {
                        sheetName = headerEl.childNodes[0].textContent.trim();
                    }
                }
                sheetName = sheetName.substring(0, 31); // Excel sheet 名称限制
                var ws;
                // 检查返回的数据格式并创建工作表

                if (Array.isArray(data) && data.length > 0) {
                    // ws = XLSX.utils.json_to_sheet(data);
                    // 获取原始表格的表头
                    var headerRow = table.querySelector('thead tr');
                    var headers = [];
                    if (headerRow) {
                        var thElements = headerRow.querySelectorAll('th');
                        thElements.forEach(function (th) {
                            headers.push(th.textContent.trim());
                        });
                    }

                    // 如果有表头，使用表头作为第一行
                    if (headers.length > 0) {
                        // 将数据转换为二维数组格式，第一行为表头
                        var sheetData = [headers];
                        data.forEach(function (row) {
                            var rowData = Object.values(row);
                            sheetData.push(rowData);
                        });
                        ws = XLSX.utils.aoa_to_sheet(sheetData);
                    } else {
                        // 如果没有表头，使用原表格
                        ws = createWorksheetFromVisibleRows(table);
                    }
                } else {
                    // 如果没有数据或数据格式不对，使用原表格
                    ws = createWorksheetFromVisibleRows(table);
                }

                XLSX.utils.book_append_sheet(wb, ws, sheetName);
            }

            // 导出文件
            XLSX.writeFile(wb, (fileName || "选中表格导出") + ".xlsx");

        } catch (error) {
            console.error('导出失败:', error);
            alert('导出失败: ' + error.message);
        }
    }

    // 从可见行创建工作表（忽略隐藏行）
    function createWorksheetFromVisibleRows(table) {
        // 创建表格的副本
        var tableClone = table.cloneNode(true);

        // 移除包含隐藏td的行
        var rowsWithHiddenTd = tableClone.querySelectorAll('tbody tr');
        rowsWithHiddenTd.forEach(function (row) {
            var tds = row.querySelectorAll('td');

            // 检查该行是否有任何td是隐藏的
            tds.forEach(function (td) {
                var style = td.getAttribute('style') || '';
                if (style.includes('display: none') || style.includes('display:none')) {
                    td.remove();
                }
            });

        });

        // 从处理后的表格创建工作表
        return XLSX.utils.table_to_sheet(tableClone);
    }
    return {
        addCheckboxes: addCheckboxes,
        exportSelected: exportSelected
    };
})();
