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

    // 导出选中的表格
    function exportSelected(fileName) {
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

        checkboxes.forEach(function (cb, order) {
            var index = cb.dataset.tableIndex;
            var table = tables[index];
            if (!table) return;

            var headerEl = table.closest(".export").querySelector(".layui-card-header");
            var sheetName = "表" + (order + 1);
            if (headerEl) {
                sheetName = headerEl.childNodes[0] ? headerEl.childNodes[0].textContent.trim() : sheetName;
            }
            sheetName = sheetName.substring(0, 31); // Excel sheet 名称限制

            var ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });
        XLSX.writeFile(wb, (fileName || "选中表格导出") + ".xlsx");
    }

    return {
        addCheckboxes: addCheckboxes,
        exportSelected: exportSelected
    };
})();
