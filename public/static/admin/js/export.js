var ExportSelectTables = (function () {

    function addCheckboxes() {
        var tables = document.querySelectorAll(".export");
        tables.forEach(function (card, index) {
            if (card.dataset.exportCheckboxAdded) return; // 避免重复添加
            var header = card.querySelector(".layui-card-header");
            var labelText = header.childNodes[0] ? header.childNodes[0].textContent.trim() : "表" + (index + 1);
            var wrapper = document.createElement("div");
            wrapper.className = "table-export-select";
            wrapper.style.margin = "8px 0";

            var checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.className = "table-checkbox";
            checkbox.dataset.tableIndex = index;

            var label = document.createElement("label");
            label.style.marginLeft = "6px";
            label.innerText = "选择导出: " + labelText;

            wrapper.appendChild(checkbox);
            wrapper.appendChild(label);

            card.parentNode.insertBefore(wrapper, card);

            card.dataset.exportCheckboxAdded = "true";
        });
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

                var headerEl = table.closest(".export").querySelector(".layui-card-header");
                var sheetName = "表" + (i + 1);
                if (headerEl) {
                    sheetName = headerEl.childNodes[0] ? headerEl.childNodes[0].textContent.trim() : sheetName;
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
                        ws = XLSX.utils.table_to_sheet(table);
                    }
                } else {
                    // 如果没有数据或数据格式不对，使用原表格
                    ws = XLSX.utils.table_to_sheet(table);
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

    return {
        addCheckboxes: addCheckboxes,
        exportSelected: exportSelected
    };
})();
