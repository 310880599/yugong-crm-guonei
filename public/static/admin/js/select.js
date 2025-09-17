class EditableSelect {
  /**
      * @param {HTMLInputElement} element - 原始 input
      * @param {Array} options - 下拉选项数组，可以是字符串或对象 {id, name}
      * @param {string} defaultText - 默认提示文字
      * @param {string} defaultValue - 默认值
      * @param {string} fieldName - 值的键名
      * @param {boolean} freeInput - 是否允许自由输入
      */
  constructor(element, options = [], defaultText = '', defaultValue = null, fieldName = 'id', freeInput = true) {


    this.original = element;
    this.options = options.map(opt => typeof opt === 'string' ? { id: opt, name: opt } : opt);
    this.filteredOptions = this.options.slice();
    this.selectedIndex = -1;
    if(!freeInput){
      this.defaultText = '请选择' + defaultText;
    }else{
      this.defaultText = '请选择/输入' + defaultText;
    }
    this.defaultValue = defaultValue;
    this.fieldName = fieldName;
    this.freeInput = freeInput;

    // 隐藏原始 input
    this.original.type = "hidden";

    // 创建容器
    this.wrapper = document.createElement('div');
    this.wrapper.className = 'layui-unselect layui-form-select';
    this.wrapper.style.position = 'relative';

    // 标题区域
    this.title = document.createElement('div');
    this.title.className = 'layui-select-title';
    this.wrapper.appendChild(this.title);

    // 可见输入框
    this.input = document.createElement('input');
    this.input.type = 'text';
    this.input.placeholder = this.defaultText;
    this.input.className = 'layui-input layui-unselect';
    this.title.appendChild(this.input);

    // 下拉箭头
    this.arrow = document.createElement('i');
    this.arrow.className = 'layui-edge';
    this.title.appendChild(this.arrow);

    // 下拉容器
    this.dl = document.createElement('dl');
    this.dl.className = 'layui-anim layui-anim-upbit';
    this.dl.style.display = 'none';
    this.wrapper.appendChild(this.dl);

    // 替换到页面
    element.parentElement.appendChild(this.wrapper);

    // ✅ 初始化默认值
    if (this.defaultValue) {
      this.original.value = this.defaultValue;
      this._syncFromOriginal();

      // const found = this.options.find(o => String(o[this.fieldName]) === String(this.defaultValue));
      // if (found) {
      //   this.original.value = found[this.fieldName];
      //   this.input.value = found.name;
      // } else {
      //   this.original.value = this.defaultValue;
      //   this.input.value = this.defaultValue;
      // }
    }

    // 渲染下拉选项
    this._renderDropdown(this.filteredOptions);
    this._bindEvents();

    // ✅ 劫持原始 input.value，保证外部赋值时 UI 同步
    const self = this;
    const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    Object.defineProperty(this.original, 'value', {
      get() {
        return descriptor.get.call(this);
      },
      set(newVal) {
        descriptor.set.call(this, newVal);
        self._syncFromOriginal();
      }
    });
  }
  _syncFromOriginal() {
    const val = this.original.value;
    if (!val) {
      this.input.value = '';
      return;
    }

    const found = this.options.find(o => String(o[this.fieldName]) === String(val));
    if (found) {
      this.input.value = found.name;  // 如果匹配到 → 显示 name
    } else {
      this.input.value = val;         // 没匹配到 → 显示原值
    }
  }

  _renderDropdown(list) {
    this.dl.innerHTML = '';

    // 默认提示项
    const firstDd = document.createElement('dd');
    firstDd.innerText = this.defaultText;
    firstDd.setAttribute('lay-value', '');
    firstDd.className = 'layui-select-tips';
    if (!this.original.value) firstDd.classList.add('layui-this');
    this.dl.appendChild(firstDd);

    if (list.length === 0) {
      const dd = document.createElement('dd');
      dd.innerText = '无匹配项';
      dd.setAttribute('lay-value', '');
      dd.className = 'layui-select-tips';
      this.dl.appendChild(dd);
      return;
    }

    list.forEach((item, index) => {
      const dd = document.createElement('dd');
      dd.innerText = item.name;
      dd.setAttribute('lay-value', item[this.fieldName]);
      if (this.original.value === String(item[this.fieldName])) dd.classList.add('layui-this');
      if (index === this.selectedIndex) dd.classList.add('layui-this');
      this.dl.appendChild(dd);
    });
  }

  _bindEvents() {
    const self = this;
    const adjustDropdownPosition = () => {
      const rect = self.wrapper.getBoundingClientRect();
      const dropdownHeight = self.dl.offsetHeight || 200;
      const spaceBelow = window.innerHeight - rect.bottom;
      const spaceAbove = rect.top;
      if (spaceBelow < dropdownHeight && spaceAbove > dropdownHeight) {
        self.dl.style.bottom = rect.height + 'px';
        self.dl.style.top = 'auto';
      } else {
        self.dl.style.top = rect.height + 'px';
        self.dl.style.bottom = 'auto';
      }
    };

    // const showDropdown = () => {
    //   self.filteredOptions = self.options.slice();
    //   self.selectedIndex = -1;
    //   self._renderDropdown(self.filteredOptions);
    //   self.dl.style.display = 'block';
    //   adjustDropdownPosition();
    // };

    const toggleDropdown = () => {
      if (self.dl.style.display === 'block') {
        self.dl.style.display = 'none';
      } else {
        self.filteredOptions = self.options.slice();
        self.selectedIndex = -1;
        self._renderDropdown(self.filteredOptions);
        self.dl.style.display = 'block';
        adjustDropdownPosition();
      }
    };



    this.input.addEventListener('click', toggleDropdown);
    this.arrow.addEventListener('click', toggleDropdown);

    // 输入搜索
    this.input.addEventListener('input', () => {
      const val = self.input.value.toLowerCase();
      self.filteredOptions = self.options.filter(item => item.name.toLowerCase().includes(val));
      self.selectedIndex = -1;
      self._renderDropdown(self.filteredOptions);
      self.dl.style.display = self.filteredOptions.length ? 'block' : 'none';
      adjustDropdownPosition();
    });

    this.input.addEventListener('blur', () => {
      const val = this.input.value.trim();
      if (!val) {
        this.original.value = '';
        return;
      }

      const found = this.options.find(o => o.name === val);
      if (found) {
        this.original.value = found[this.fieldName];   // ✅ 下拉里有匹配 → 保存 id
      } else if (this.freeInput) {
        this.original.value = val;        // ✅ 允许自由输入 → 保存文本
      } else {
        this.original.value = '';         // ❌ 严格模式，不允许自由输入
        this.input.value = '';               // 清空输入框
      }
    });
    // 点击选择
    this.dl.addEventListener('click', e => {
      if (e.target.tagName.toLowerCase() === 'dd') {
        const val = e.target.getAttribute('lay-value');
        const text = e.target.innerText;
        self.input.value = val ? text : '';
        self.original.value = val || '';   // ✅ 表单提交用
        self._renderDropdown(self.filteredOptions);
        self.dl.style.display = 'none';
      }
    });

    // 键盘操作
    this.input.addEventListener('keydown', e => {
      const items = Array.from(self.dl.querySelectorAll('dd')).filter(dd => dd.style.display !== 'none');
      if (!items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        self.selectedIndex = (self.selectedIndex + 1) % items.length;
        self._renderDropdown(self.filteredOptions);
        adjustDropdownPosition();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        self.selectedIndex = (self.selectedIndex - 1 + items.length) % items.length;
        self._renderDropdown(self.filteredOptions);
        adjustDropdownPosition();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (self.selectedIndex >= 0) {
          const dd = items[self.selectedIndex];
          const val = dd.getAttribute('lay-value');
          const text = dd.innerText;
          self.input.value = val ? text : '';
          self.original.value = val || '';  // ✅ 表单提交用
          self._renderDropdown(self.filteredOptions);
          self.dl.style.display = 'none';
        }
      } else if (e.key === 'Escape') {
        self.dl.style.display = 'none';
      }
    });

    // 点击外部关闭
    document.addEventListener('click', e => {
      if (!self.wrapper.contains(e.target)) {
        self.dl.style.display = 'none';
      }
    });
  }

  // ✅ 对外方法
  getValue() { return this.original.value || ''; } // 表单提交用
  getText() { return this.input.value; }           // 显示的文本
  setOptions(options) {
    this.options = options.map(opt => typeof opt === 'string' ? { id: opt, name: opt } : opt);
    this.filteredOptions = this.options.slice();
    this._renderDropdown(this.filteredOptions);
    this._syncFromOriginal();
  }
  clear() {
    this.input.value = '';
    this.original.value = '';
    this.selectedIndex = -1;
    this._renderDropdown(this.filteredOptions);
  }
}
// 使用示例
// const khStatusList = ["阿里","速卖通","SEM","SEO","抖音","亚马逊","中国制造","返单","老客户转介绍","其他","23123"];
// const khSelect = new EditableSelect(document.getElementById('kh_status_input'), khStatusList);