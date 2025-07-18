"use strict";
try {
    if (VERBOSE) console.log('admin.js loaded');
} catch (e) {
    window.VERBOSE = false; // todo better scoping...
}
// declare global admin
window.CMS_admin = true;

/**
 * PEATCMS_actor is a column in an element
 */
function PEATCMS_actor(column_name, PEATCMS_element) {
    this.pretty_id_column_names = ['brand_id', 'serie_id', 'product_id', 'image_id', 'property_id'];
    this.price_column_names = ['price', 'price_from'];
    this.selectlist_column_names = ['template_id', 'vat_category_id'];
    this.changed = false;
    this.parent_PEATCMS_element = PEATCMS_element; // todo make this a reference to the element
    this.column = PEATCMS_element.getColumnByName(column_name);
    this.server_value = PEATCMS_element.getColumnValue(column_name);
    if (typeof this.server_value === 'undefined') PEAT.message('Cache must be refreshed', 'warn');
    if ((this.DOMElement = this.create_DOMElement())) {
        this.DOMElement.id = `admin_${column_name}`;
        return; // prevent error message from appearing
    }
    if (VERBOSE) console.error(`There is no ${this.column.type} for ${column_name}`);
    PEATCMS_element = null;
}

/**
 * Creates an appropriate DOM Element for the actor and returns it
 * @returns {HTMLDivElement|void|*|undefined|HTMLSelectElement|HTMLLabelElement|HTMLInputElement}
 */
PEATCMS_actor.prototype.create_DOMElement = function () {
    const column = this.column,
        type = column.type;
    column.actor = this;
    if (false === column['editable']) {
        const el = document.createElement('input');
        el.classList.add('uneditable');
        el.title = column.name;
        el.setAttribute('disabled', 'disabled');
        el.setAttribute('tabindex', '-1'); // not working at the moment (?)
        //div.innerHTML = column_name + ': ' + this.getColumnValue(column_name);
        el.value = this.server_value;
        return el;
    }
    if (type === 'boolean') {
        return this.create_as_checkbox(column);
    } else if (type === 'character' || type === 'text') {
        if (column.name === 'filename_saved') {
            return this.create_as_file_upload(column);
        } else if (column.hasOwnProperty('constrained_values') && Array.isArray(column.constrained_values)) {
            return this.create_as_select(column);
        } else {
            return this.create_as_input(column);
        }
    } else if (type === 'integer') {
        return this.create_as_numeric(column);
    } else if (type === 'timestamp') {
        return this.create_as_date(column);
    }
}

PEATCMS_actor.prototype.create_as_checkbox = function (column) {
    const el = document.createElement('input'),
        lbl = document.createElement('label'),
        span = document.createElement('span'),
        self = this;
    el.type = 'checkbox';
    el.checked = this.server_value;
    el.id = `checkbox_${column.name}`;
    el.addEventListener('change', function () {
        self.changedTo(this.checked);
    });
    lbl.insertAdjacentText('afterbegin', column.name);
    lbl.setAttribute('for', el.id);
    lbl.tabIndex = 0;
    lbl.addEventListener('keydown', function (e) {
        if (' ' === e.key) {
            e.preventDefault();
            e.stopPropagation();
            this.click();
        }
    });
    span.classList.add('checkbox');
    span.insertAdjacentElement('afterbegin', lbl);
    span.insertAdjacentElement('afterbegin', el);
    return span;
}

PEATCMS_actor.prototype.create_as_select = function (column) {
    if (!column.hasOwnProperty('constrained_values')) return;
    const el = document.createElement('select'),
        self = this;
    let option_index,
        option_value,
        option;
    for (option_index in column.constrained_values) {
        if (column.constrained_values.hasOwnProperty(option_index)) {
            option_value = column.constrained_values[option_index];
            option = document.createElement('option');
            option.appendChild(document.createTextNode(option_value));
            option.value = option_value;
            el.appendChild(option);
            if (this.server_value === option_value) el.selectedIndex = option_index;
        }
    }
    el.addEventListener('change', function () {
        self.changedTo(this.options[this.selectedIndex].value);
    });
    return el;
}

PEATCMS_actor.prototype.create_as_date = function (column) {
    if (column.name === 'date_popvote') { // the ‘date_upvoted’ gets a special interface at the top of the edit bar
        const self = this,
            border = document.createElement('div'),
            el = document.createElement('div'),
            up = document.createElement('button'),
            places = document.createElement('input'),
            down = document.createElement('button');
        up.innerText = '↸';
        up.addEventListener('click', function () {
            NAV.ajax('/__action__/admin_popvote/', {
                direction: 'up',
                id: self.parent_PEATCMS_element.getElementId(),
                element_name: self.parent_PEATCMS_element.getElementName(),
            }, function (json) {
                self.showPopVote(json);
            });
        });
        places.type = 'number';
        places.value = PEAT.getSessionVar('pop_vote_places') || '1';
        places.addEventListener('change', function () {
            PEAT.setSessionVar('pop_vote_places', this.value);
        })
        down.innerText = '↘';
        down.addEventListener('click', function () {
            NAV.ajax('/__action__/admin_popvote/', {
                direction: 'down',
                places: PEAT.getSessionVar('pop_vote_places'),
                id: self.parent_PEATCMS_element.getElementId(),
                element_name: self.parent_PEATCMS_element.getElementName(),
            }, function (json) {
                self.showPopVote(json);
            });
        });
        el.insertAdjacentElement('afterbegin', down);
        el.insertAdjacentElement('afterbegin', places);
        el.insertAdjacentElement('afterbegin', up);
        NAV.ajax('/__action__/admin_popvote/', {
            direction: 'get',
            id: self.parent_PEATCMS_element.getElementId(),
            element_name: self.parent_PEATCMS_element.getElementName(),
        }, function (json) {
            self.showPopVote(json);
        });
        el.classList.add('inner');
        border.classList.add('border');
        border.appendChild(el);
        return border;
    } else { // make a date thingie
        const el = this.create_as_input(column);
        if (el.value === '') {
            this.update('NOW()', 'set')
        }
        return el;
    }
}
PEATCMS_actor.prototype.showPopVote = function (json) {
    if (json.hasOwnProperty('pop_vote')) { // pop_vote is a float between 0 (most popular) and 1 (least...)
        const vote = json.pop_vote,
            deg = 360 * vote;
        this.DOMElement.style.backgroundImage = `conic-gradient(from 270deg, var(--admin-color-accent-transparent) 0deg, var(--admin-color-accent-transparent) ${deg}deg, var(--admin-color-accent) ${deg}deg)`;
        this.DOMElement.title = vote;
    }
}

PEATCMS_actor.prototype.create_as_input = function (column) {
    const self = this;
    let el;
    if (column.length > 127) {
        el = document.createElement('textarea');
    } else {
        el = document.createElement('input');
        el.setAttribute('autocomplete', 'off');
        if (['smallint', 'integer', 'bigint'].includes(column.type)) {
            el.type = 'number';
        } else if ('email' === column.type) {
            el.type = 'email';
        } else {
            el.type = 'text'
        }
    }
    el.value = this.server_value || '';
    el.placeholder = this.column.name;
    el.addEventListener('keyup', function (event) {
        self.typed(event, el.type !== 'textarea');
    });
    el.addEventListener('keydown', function (event) {
        self.keydown(event);
    });
    el.addEventListener('blur', function () {
        self.update(el.value, 'set');
    });
    return el;
}

PEATCMS_actor.prototype.create_as_numeric = function (column) {
    const self = this, column_name = column.name,
        selectlist_actions = {
            template_id: '/__action__/admin_get_templates',
            vat_category_id: '/__action__/admin_get_vat_categories'
        };
    let el, element_name, column_names, option
    // this can actually be a numeric field,
    // but it can also be one of the id's, in that case create a searchbox to connect with (parent) element(s)
    if (this.pretty_id_column_names.includes(column_name)) {
        el = document.createElement('input');
        el.setAttribute('autocomplete', 'off');
        el.setAttribute('type', 'text');
        element_name = column_name.replace('_id', '');
        column_names = this.parent_PEATCMS_element.getColumnNames();
        el.classList.add('peatcms_loading', 'pretty_parent', element_name);
        //el.setAttribute('data-id', self.server_value);
        el.placeholder = element_name;
        this.prettyParent(this.server_value);
        // check if this is the final one in the product -> serie -> brand chain, otherwise you can't edit this
        if (element_name === 'serie' && column_names.includes('variant_id') ||
            element_name === 'brand' && column_names.includes('product_id')) {
            //console.log(column_names);
            el.setAttribute('disabled', 'disabled');
            return el;
        }
        el.addEventListener('focus', function () {
            this.value = '';
            this.classList.add('searchable', 'unsaved');
            self.suggestParent(element_name);
        });
        el.addEventListener('blur', function () {
            /*window.setTimeout(function () {
                if (el.classList.contains('searchable')) {
                    self.prettyParent(self.server_value);
                }
            }, 1000);*/
        });
        el.addEventListener('keyup', function (e) {
            if (e.key === 'Escape') {
                self.prettyParent(self.server_value);
            } else {
                self.suggestParent(element_name);
            }
        });
        return el;
    } else if (this.selectlist_column_names.includes(column_name)) { // setup select list
        el = document.createElement('select')
        option = document.createElement('option');
        option.text = column_name.replace('_id', '...');
        option.value = '0';
        el.options[0] = option;
        el.classList.add('peatcms_loading', column_name.replace('_id', ''));
        el.setAttribute('data-peatcms_value', this.server_value);
        // load the options and on return update the select list
        NAV.ajax(selectlist_actions[column_name], {for: this.parent_PEATCMS_element.state.type_name}, function (json) {
            const el = self.DOMElement;
            let i, option, temp, option_value_exists = false;
            for (i in json) {
                if (json.hasOwnProperty(i) && (temp = json[i]) && temp.hasOwnProperty(column_name)) {
                    option = document.createElement('option');
                    option.text = temp.name || temp.title;
                    option.value = temp[column_name];
                    el.options[el.length] = option;
                    if (temp[column_name] === self.server_value) {
                        el.selectedIndex = el.length - 1;
                        option_value_exists = true;
                    }
                }
            }
            el.classList.remove('peatcms_loading');
            if (el.options[1]) {
                if (self.server_value === 0 || false === option_value_exists) { // select first one by default
                    self.changedTo(el.options[1].value);
                }
                el.options[0].remove(); // remove nonsense option
            }
        });
        el.addEventListener('change', function () {
            self.changedTo(this.options[this.selectedIndex].value);
        });
        return el;
    } else if ('quantity_in_stock' === column_name) {
        const value = this.server_value,
            display = (null === value) ? '∞' : value,
            self = this;
        el = document.createElement('div');
        el.setAttribute('data-quantity', value);
        el.innerHTML = `Quantity: <span class="quantity">${display}</span>`;

        // update quantity on server
        function updateQuantity(quantity) {
            NAV.ajax('/__action__/admin_update_quantity_in_stock', {
                variant_id: self.parent_PEATCMS_element.getElementId(),
                quantity: quantity
            }, function (data) {
                self.parent_PEATCMS_element.set(data)
            });
        }

        // quantity management button
        function createQuantityButton(type, icon, label) {
            const button = document.createElement('button');
            button.classList.add(type);
            button.innerText = icon;
            button.addEventListener('click', function () {
                if ('remove' === type) {
                    if (confirm('Remove stock management from this variant?')) {
                        updateQuantity(null);
                    }
                    return;
                }
                const quantity = parseInt(prompt(label));
                if (isNaN(quantity)) return;
                if ('add' === type) {
                    updateQuantity(quantity);
                } else {
                    updateQuantity(-quantity);
                }
            });
            return button;
        }

        // add the buttons
        el.appendChild(createQuantityButton('add', '+', 'Add to stock'));
        el.appendChild(createQuantityButton('subtract', '-', 'Subtract from stock'));
        el.appendChild(createQuantityButton('remove', '×', 'Remove stock management'));

        return el;
    } else { // regular numeric field
        return this.create_as_input(column);
    }
}

PEATCMS_actor.prototype.setParent = function (id) {
    if (id === this.server_value) {
        if (VERBOSE) console.log(`Same value(${id}), does not have to be set.`);
        return;
    }
    this.parent_PEATCMS_element.chainParents(this.column.name, id);
}

PEATCMS_actor.prototype.prettyParent = function (id) {
    const element_name = this.column.name.replace('_id', ''),
        list_el = document.getElementById(`PEATCMS_suggestions_${element_name}`),
        self = this;
    if (list_el) {
        list_el.remove();
    }
    NAV.ajax('/__action__/admin_get_element', {
        'element': element_name,
        'id': id
    }, function (data) {
        const el = self.DOMElement;
        if (data.hasOwnProperty('title')) {
            el.value = data.title;
            el.placeholder = data.title;
        } else {
            el.value = '';
        }
        el.classList.remove('peatcms_loading', 'searchable', 'unsaved');
    });
}

PEATCMS_actor.prototype.suggestParent = function (element_name) {
    const el = this.DOMElement,
        self = this;
    NAV.ajax('/__action__/admin_get_element_suggestions', {
        'element': element_name,
        'src': el.value
    }, function (data) {
        let element = data.element,
            list_el = document.getElementById(`PEATCMS_suggestions_${element}`),
            i, len, div, row, rows;
        if (data.hasOwnProperty('rows')) {
            // remove the current suggestions and add new ones
            if (list_el) {
                list_el.innerHTML = '';
            } else {
                list_el = document.createElement('div');
                list_el.id = `PEATCMS_suggestions_${element}`;
                list_el.classList.add('suggestions', element);
                el.insertAdjacentElement('afterend', list_el);
            }
            rows = data.rows;
            for (i = 0, len = rows.length; i < len; ++i) {
                row = rows[i];
                div = document.createElement('div');
                div.innerHTML = row['title'];
                div.className = row['online'] ? 'online' : 'offline';
                div.setAttribute('data-id', row[element + '_id'] || row['id']);
                div.onclick = function () {
                    self.setParent(this.getAttribute('data-id'));
                };
                list_el.insertAdjacentElement('beforeend', div);
            }
        }
    });
}

PEATCMS_actor.prototype.create_as_file_upload = function (column) { // create a drop area with handlers that save the dropped file and update server side
    const drop = document.createElement('div'),
        el = document.createElement('div'),
        process = document.createElement('div'),
        self = this;
    let filename_saved, button, option;
    // @since 0.10.0 reprocess option for image type
    if ('image' === self.parent_PEATCMS_element.state.type_name) {
        process.classList.add('process_area', 'file');
        if (null === (filename_saved = self.parent_PEATCMS_element.state.filename_saved)) {
            process.innerHTML = 'Upload original to process again.';
            process.classList.add('info');
        } else {
            option = function (value, text) {
                const el = document.createElement('option');
                el.value = value;
                el.text = text;
                return el;
            }
            button = document.createElement('select');
            button.setAttribute('data-filename_saved', filename_saved);
            button.addEventListener('change', function () {
                self.process(this.options[this.selectedIndex].value);
            });
            button.classList.add('button', 'process');
            button.options.add(option(0, '▦ Re-process this image.'));
            button.options.add(option(1, 'Original quality, optimized filesize.'));
            button.options.add(option(2, 'Better quality, slower loading.'));
            button.options.add(option(3, 'Best quality, slowest loading.'));
            process.appendChild(button);
        }
    }
    // the file upload drop
    drop.addEventListener('dragenter', function (e) {
        this.classList.add('dragover');
        e.preventDefault();
        e.stopPropagation();
    }, false)
    drop.addEventListener('dragover', function (e) {
        this.classList.add('dragover');
        e.preventDefault();
        e.stopPropagation();
    }, false)
    drop.addEventListener('dragleave', function (e) {
        this.classList.remove('dragover');
        e.preventDefault();
        e.stopPropagation();
    }, false)
    drop.addEventListener('drop', function (e) {
        this.classList.remove('dragover');
        column.actor.dropFile(e);
        e.preventDefault();
        e.stopPropagation();
    }, false);
    drop.classList.add('drop_area', 'file');
    self.sse_log = function (msg) {
        let el = self.DOMElement.querySelector('.progress') || self.DOMElement.querySelector('.drop_area') || self.DOMElement;
        el.innerHTML = `${msg}<br/>${el.innerHTML}`;
        console.warn(msg);
    }
    self.process = function (level) {
        const source = new EventSource('/__action__/process_file/sse:true/level:' + (level || 1) + '/slug:' + self.parent_PEATCMS_element.state.slug);
        source.onmessage = function (event) {
            const data = JSON.parse(event.data);
            let slug;
            if (data.hasOwnProperty('message')) {
                self.sse_log(data.message);
            }
            if (data.hasOwnProperty('close')) {
                source.close();
                self.sse_log('Done.');
                if ((slug = self.parent_PEATCMS_element.state.slug) && NAV.getCurrentSlug() === slug) {
                    NAV.refresh(); // Gets the updated element from the server as well
                } else {
                    PEAT.message(`Done processing ${decodeURI(slug)}.`, 'note');
                }
            }
        };
    }
    el.appendChild(drop);
    el.appendChild(process);
    return el;
}

PEATCMS_actor.prototype.dropFile = function (event) {
    const dt = event.dataTransfer,
        files = dt.files,
        el = this.parent_PEATCMS_element,
        self = this;
    let slug = el.state.slug,
        i;
    for (i in [files]) {
        // TODO it seems only one file can be dropped at the moment, others are ignored? don't know why
        if (i === '1') { // only add the file to this file element the first time, for other elements, keep adding and linking
            if (['file', 'image'].contains(el.getElementName())) slug = false;
        }
        NAV.fileUpload(function (data) {
            self.set(data);
            self.process();
        }, files[i], slug, this.DOMElement);
    }
}

PEATCMS_actor.prototype.on = function (type, listener) {
    // TODO make sure this gets added to existing eventlisteners
    this.DOMElement.addEventListener(type, listener);
}

// noinspection JSUnusedGlobalSymbols
PEATCMS_actor.prototype.typed = function (event, enter_triggers_save) {
    this.reflectChanged();
    if (true === enter_triggers_save) {
        if (event.key === 'Enter') {//} && event.shiftKey === false) {
            this.update(this.DOMElement.value, 'set');
        }
    }
    if (event.key === 'Escape') {
        // copy the title tag... special functionality since 0.16.4
        if ((event.ctrlKey || event.metaKey) && 'slug' === this.column.name) {
            const title = NAV.getCurrentElement().state.title_parsed; // TODO use parent_PEATCMS_element when it is the right reference
            if (title) this.DOMElement.value = title;
        } else {
            this.DOMElement.value = this.server_value;
        }
        this.reflectChanged();
    }
}

PEATCMS_actor.prototype.keydown = function (event) {
    // this only handles saving
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
        // Save Function
        this.update(this.DOMElement.value, 'set');
        event.preventDefault();
        event.stopPropagation();
        event.returnValue = '';
        return false;
    }
}

PEATCMS_actor.prototype.changedTo = function (value) {
    this.reflectChanged();
    this.changed = true;
    this.update(value, 'set');
}

/**
 * Will update this actor (column of an element) with the supplied value
 * if you supply 'set' it will execute 'set' function in the parent element,
 * if you supply your own function, you need to handle the refreshing of the element yourself
 *
 * @param value
 * @param callback_method function or string the name of the method (of PEATCMS_actor) that needs to run, data is passed as argument
 */
PEATCMS_actor.prototype.update = function (value, callback_method) {
    const self = this;
    if (false === this.hasChanged()) {
        if (VERBOSE) console.log(`Not saving unchanged value for ${this.column['name']}.`);
        return;
    }
    NAV.invalidateCache(); // throw away the cache now to be on the safe side
    NAV.ajax('/__action__/update_element/', {
        'element': this.parent_PEATCMS_element.getElementName(),
        'id': this.parent_PEATCMS_element.getElementId(),
        'column_name': this.column['name'],
        'column_value': value
    }, function (data) {
        if (typeof callback_method === 'function') {
            callback_method(data);
        } else if (typeof self.parent_PEATCMS_element[callback_method] === 'function') {
            self.parent_PEATCMS_element[callback_method](data);
        } else if (typeof self[callback_method] === 'function') {
            self[callback_method](data);
        } else {
            console.error(callback_method + ' not found (in PEATCMS_actor).');
            PEAT.message('Update not reflected in DOM.', 'warn');
        }
    });
}

/**
 * Sets the current actor (column of an element)
 * @param data
 */
PEATCMS_actor.prototype.set = function (data) {
    const el = this.parent_PEATCMS_element,
        column_name = this.column['name'],
        column_value = data[column_name],
        type = el.getElementName();
    // if the id changed, a new element was created on the server, please redirect to that
    if (el.getElementId() !== data[type + '_id']) {
        if (data.hasOwnProperty('slug')) {
            NAV.go(data.slug);
            return;
        } else {
            console.warn('A new element was created on the server, state is not consistent.');
            return;
        }
    }
    this.changed = false;
    this.server_value = column_value;
    if (this.pretty_id_column_names.includes(column_name)) {
        this.prettyParent(column_value);
        return;
    }
    if (true === this.hasChanged()) {
        if ('quantity_in_stock' === column_name) {
            this.DOMElement.setAttribute('data-quantity', column_value);
            this.DOMElement.querySelector('.quantity').innerText = (null === column_value) ? '∞' : column_value;
        } else if (this.DOMElement.classList.contains('checkbox')) {
            const checkbox = this.DOMElement.querySelector('input');
            checkbox.checked = column_value;
            checkbox.dispatchEvent(new Event('change'));
        } else {
            this.DOMElement.value = column_value;
        }
        PEAT.grabAttention(this.DOMElement, true);
    }
    el.state[column_name] = column_value; // set the editable element to the new values
    this.DOMElement.classList.remove('unsaved');
}

PEATCMS_actor.prototype.hasChanged = function () {
    const DOMElement = this.DOMElement;
    let el, current_value = '';
    if ('undefined' === typeof DOMElement) return true; // forcing a specific value with update() is alright
    if ('undefined' !== typeof DOMElement.selectedIndex) {
        // select lists always return option value as string
        if ('undefined' !== typeof (el = DOMElement.options[DOMElement.selectedIndex])) {
            current_value = el.value;
        } else if (VERBOSE) {
            console.error('Options in select error', DOMElement);
        }
        return this.changed || (this.server_value.toString() !== current_value);
    }
    if (false === DOMElement.hasAttribute('type')) { // these may be embellished checkboxes and date_popvoted
        if ((el = DOMElement.querySelector('[type="checkbox"]'))) {
            return el.checked !== this.server_value;
        }
        if ('date_popvote' === this.column['name']) {
            // for now we do not update this
            return false;
        }
    }
    return this.server_value !== this.DOMElement.value || this.changed;
}

PEATCMS_actor.prototype.reflectChanged = function () {
    if (true === this.hasChanged()) {
        this.DOMElement.classList.add('unsaved');
    } else {
        this.DOMElement.classList.remove('unsaved');
    }
}

function PEATCMS_x_value(row, parent_element) { // contains property / property_value x_value triple cross table
    const el = document.createElement('div'),
        self = this;
    let btn, input;
    this.row = row;
    this.parent_element = parent_element;
    //this.property = window.PEATCMS_globals.slugs[row.__property__.__ref];
    el.className = (row.online) ? 'online' : 'offline';
    el.innerHTML = `<span class="drag_handle">::</span> <a href="/${row.property_slug}">${row.property_title}</a>: <a href="/${row.slug}">${row.title}</a>`;
    // add remove button
    btn = document.createElement('span');
    btn.classList.add('remove');
    btn.classList.add('button');
    btn.setAttribute('tabindex', '0');
    btn.innerHTML = '×';
    btn.addEventListener('click', function () {
        NAV.ajax('/__action__/admin_x_value_remove', {
            x_value_id: row.x_value_id,
            id: parent_element.getElementId(),
            element: parent_element.getElementName()
        }, function (data) {
            parent_element.setLinked('x_value', data);
            parent_element.populatePropertiesArea('x_value');
        });
    });
    el.insertAdjacentElement('beforeend', btn);
    // add x_value update input (flexible)
    input = document.createElement('input');
    input.type = 'checkbox';
    input.title = 'Use extra value';
    input.classList.add('flexible');
    if (PEATCMS.trim(row.property_value_uses_x_value)) {
        input.checked = true;
    } else {
        input.classList.add('visible');
    }
    el.insertAdjacentElement('beforeend', input);
    input = document.createElement('input');
    input.value = row.x_value;
    input.type = 'text';
    input.classList.add('flexible');
    input.addEventListener('mousedown', function (e) {
        e.stopPropagation();
        this.parentNode.setAttribute('draggable', 'false');
    });
    input.addEventListener('mouseup', function () {
        this.parentNode.setAttribute('draggable', 'true');
    });
    input.addEventListener('mouseleave', function () {
        this.parentNode.setAttribute('draggable', 'true');
    });
    input.setAttribute('data-column_name', 'x_value');
    input.setAttribute('data-table_name', `cms_${parent_element.getElementName()}_x_properties`);
    input.setAttribute('data-peatcms_handle', 'update');
    input.setAttribute('data-peatcms_id', row.x_value_id);
    new PEATCMS_column_updater(input, parent_element);
    el.appendChild(input);
    // prepare the element for drag and drop
    el.PEATCMS_x_value = this;
    el.setAttribute('data-x_value', row.x_value);
    el.setAttribute('data-x_value_id', row.x_value_id);
    // make draggable
    el.setAttribute('draggable', 'true');
    el.addEventListener('dragstart', function (event) {
        event.dataTransfer.setData('x_value_id', row.x_value_id);
    });
    el.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (!event.dataTransfer.types.includes('x_value_id')) return;
        this.classList.add('order', 'dragover');
    });
    el.addEventListener('dragleave', function () {
        this.classList.remove('dragover');
    });
    el.addEventListener('drop', function (event) {
        if (!event.dataTransfer.types.includes('x_value_id')) return;
        const dropped_x_value_id = parseInt(event.dataTransfer.getData('x_value_id'));
        this.classList.remove('dragover');
        NAV.ajax('/__action__/admin_x_value_order/', {
            'element': self.parent_element.getElementName(),
            'id': self.parent_element.getElementId(),
            'linkable_type': self.name,
            'x_value_id': dropped_x_value_id,
            'before_x_value_id': self.row.x_value_id
        }, function (data) {
            parent_element.setLinked('x_value', data);
            parent_element.populatePropertiesArea('x_value');
        });
        try {
            event.dataTransfer.clearData();
        } catch (e) {
            if (VERBOSE) console.error(e);
        }
    });
    // set
    this.DOMElement = el;
}

const PEATCMS_linkable = function (name, row, parent_element) {
    const el = document.createElement('div'),
        self = this;
    this.name = name;
    this.row = row;
    this.parent_element = parent_element;
    el.className = (row.online) ? 'online' : 'offline';
    el.innerHTML = `<span class="drag_handle">::</span> <a href="/${row.slug}">${row.title}</a> `;
    el.appendChild(this.getLinkButton());
    el.PEATCMS_linkable = this;
    el.setAttribute('data-peatcms_slug', row.slug);
    // make draggable TODO create standard method for draggable ordering
    el.setAttribute('draggable', 'true');
    el.addEventListener('dragstart', function (event) {
        event.dataTransfer.setData('slug', row.slug);
        event.dataTransfer.setData(self.getTypeName(), ''); // add element type to the types of dataTransfer
    });
    el.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (!event.dataTransfer.types.includes(el.PEATCMS_linkable.getTypeName())) return;
        this.classList.add('order', 'dragover');
    });
    el.addEventListener('dragleave', function () {
        this.classList.remove('dragover');
    });
    el.addEventListener('drop', function (event) {
        if (!event.dataTransfer.types.includes(el.PEATCMS_linkable.getTypeName())) return;
        const dropped_slug = event.dataTransfer.getData('slug');
        this.classList.remove('dragover');
        this.parentNode.classList.add('peatcms_loading');
        NAV.ajax('/__action__/admin_linkable_order/', {
            'element': self.parent_element.getElementName(),
            'id': self.parent_element.getElementId(),
            'linkable_type': self.name,
            'slug': dropped_slug,
            'before_slug': self.row.slug,
            'full_feedback': ('property_value' !== self.name) // TODO make this a setting
        }, function (data) {
            self.parent_element.setLinked(self.name, data);
            self.parent_element.populateLinkableArea(self.name);
        });
        try {
            event.dataTransfer.clearData();
        } catch (e) {
            if (VERBOSE) console.error(e);
        }
    });
    // set
    this.DOMElement = el;
}

PEATCMS_linkable.prototype.getTypeName = function () {
    return this.row.type_name || this.row.table_name.slice(4);
}

PEATCMS_linkable.prototype.getLinkButton = function () {
    if (!this.DOMLinkButton) {
        const self = this,
            el = document.createElement('button');
        el.addEventListener('click', function () {
            self.toggleLink();
        });
        this.DOMLinkButton = el;
    }
    return this.DOMLinkButton;
}

PEATCMS_linkable.prototype.toggleLink = function () {
    const self = this;
    NAV.ajax('/__action__/admin_linkable_link/', {
        'element': this.parent_element.getElementName(),
        'id': this.parent_element.getElementId(),
        'sub_element': this.name,
        'sub_id': this.getId(),
        'unlink': this.isLinked()
    }, function (data) {
        self.parent_element.setLinked(self.name, data[self.name]);
        // update list of linked items
        self.parent_element.populateLinkableArea(self.name);
    });
}

PEATCMS_linkable.prototype.getId = function () {
    return this.row['id'] || this.row[this.name + '_id']; // todo fix why sometimes the whole element is in the row but not the id then
}

PEATCMS_linkable.prototype.isLinked = function () {
    return this.parent_element.hasLinked(this.name, this.getId());
}

function PEATCMS_searchable(name, parent_element) {
    const el = document.createElement('input');
    this.name = name;
    this.parent_element = parent_element;
    //el.id = 'searchable_' + name;
    el.actor = this;
    el.type = 'text';
    el.classList.add('searchable');
    el.placeholder = 'search';
    el.addEventListener('keyup', function (event) {
        this.actor.type(event);
    });
    el.addEventListener('focus', function () {
        this.actor.search(el.value); // startup with all results on focus
    });
    this.DOMElement = el;
}

PEATCMS_searchable.prototype.type = function () {
    this.search(this.DOMElement.value);
}

PEATCMS_searchable.prototype.search = function (src) {
    const this_parent_element = this.parent_element,
        self = this;
    clearTimeout(this.search_timeout); // THROTTLE
    this.search_timeout = setTimeout(function () {
        self.timestamp = Date.now();
        self.DOMElement.classList.add('peatcms_loading');
        NAV.ajax('/__action__/admin_get_element_suggestions/', {
            element: self.name,
            src: src,
            timestamp: self.timestamp
        }, function (data) {
            //console.warn(self.timestamp +' === '+ data['timestamp']);
            if (self.timestamp === data['timestamp']) {
                //console.log(data);
                // TODO this function knows too much, should be a clean callback in stead of the PEATCMS_element :-(
                if (this_parent_element.populateLinkableArea) {
                    this_parent_element.populateLinkableArea(data['element'], data['rows'], data['src']);
                } else { // assume a callback function...
                    this_parent_element(data['element'], data['rows']);
                }
                self.DOMElement.classList.remove('peatcms_loading');
            }// else {console.error('fail');}
        });
    }, 493);
}

// TODO edit mechanism is now: open the sidebar panel and load the page, which will load the edit fields if the sidebar is open, but that's not robust

// build a standard recursive ul -> li menu using menu and menu_item that an admin can edit
const PEATCMS_admin_menu_item = function (row, droppable = false) {
    const el = document.createElement('li');
    let menu, btn;
    if (row.hasOwnProperty('menu_item_id')) {
        el.menu_item = row;
        el.setAttribute('data-menu_item_id', row.menu_item_id);
        el.insertAdjacentHTML('beforeend', '<span class="drag_handle">::</span> ');
        el.insertAdjacentHTML('beforeend', row.title);
        btn = document.createElement('button');
        btn.addEventListener('click', function () {
            if (this.parentNode.hasOwnProperty('menu_item') && CMS_admin) {
                CMS_admin.edit(`/__admin__/menu_item/${this.parentNode.menu_item.menu_item_id}`);
            } else {
                console.error('Could not edit menu item.');
            }
        });
        btn.innerHTML = 'EDIT';
        el.insertAdjacentElement('beforeend', btn);
        if (row.hasOwnProperty('__menu__')) {
            menu = row.__menu__;
            el.insertAdjacentElement('beforeend', new PEATCMS_admin_menu(menu).DOMElement);
        }
        /**
         * menu items can be dragged around
         */
        el.setAttribute('draggable', 'true');
        el.addEventListener('dragstart', function (event) {
            event.dataTransfer.setData('menu_item_id', row.menu_item_id);//this.getAttribute('data-menu_item_id'));
            // don't set the id's of the parents as well
            event.stopPropagation();
        });
        if (droppable === true) {
            /**
             * you can only drop on items in the menu editor, which can be duplicates so pay attention to that as well
             * and you have two drop zones: to create a child and to simply order the items
             */
            el.addEventListener('dragover', function (event) {
                let div;
                event.preventDefault();
                event.stopPropagation(); // for child items, don't dragover the parent as well
                // if the active-for-drop item is not the current dragover item, reset it
                if ((div = document.getElementById('sub_menu_item_drop'))) {
                    if (div.getAttribute('data-menu_item_id') !== this.getAttribute('data-menu_item_id')) {
                        div.remove();
                        document.querySelectorAll('.dragover').forEach(function (el) {
                            el.classList.remove('dragover');
                        });
                    }
                }
                // setup the current active-for-drop item if not already setup
                if (!(div = document.getElementById('sub_menu_item_drop'))) {
                    this.classList.add('dragover');
                    // add the child-creation dropzone
                    div = document.createElement('div');
                    div.className = 'drop_area';
                    div.id = 'sub_menu_item_drop';
                    div.setAttribute('data-menu_item_id', this.getAttribute('data-menu_item_id'));
                    div.addEventListener('drop', function (event) {
                        const dropped_menu_item_id = event.dataTransfer.getData('menu_item_id');
                        // send to server
                        // noinspection JSPotentiallyInvalidUsageOfThis
                        CMS_admin.putMenuItem({
                            // 'this' is no longer 'el', but now it is 'div'
                            menu_item_id: parseInt(this.getAttribute('data-menu_item_id')),
                            dropped_menu_item_id: parseInt(dropped_menu_item_id),
                            command: 'child',
                            menu: NAV.getCurrentSlug(),
                        });
                        //
                        try {
                            event.dataTransfer.clearData();
                        } catch (e) {
                            console.error(e);
                        }
                        // don't drop onto parent menu's as well:
                        event.stopPropagation();
                    });
                    div.addEventListener('dragover', function (event) {
                        event.preventDefault();
                        // noinspection JSPotentiallyInvalidUsageOfThis (this is the div)
                        this.classList.add('dragover');
                        this.parentNode['classList'].remove('dragover');
                    });
                    div.addEventListener('dragleave', function (event) {
                        event.preventDefault();
                        // noinspection JSPotentiallyInvalidUsageOfThis (this is the div)
                        this.classList.remove('dragover');
                        this.parentNode['classList'].add('dragover');
                    });
                    this.insertAdjacentElement('beforeend', div);
                }
            });
            // drop here for ordering the items
            el.addEventListener('drop', function (event) {
                const dropped_menu_item_id = event.dataTransfer.getData('menu_item_id');
                // send to server
                CMS_admin.putMenuItem({
                    menu_item_id: parseInt(this.getAttribute('data-menu_item_id')),
                    dropped_menu_item_id: parseInt(dropped_menu_item_id),
                    command: 'order',
                    menu: NAV.getCurrentSlug(),
                });
                //
                try {
                    event.dataTransfer.clearData();
                } catch (e) {
                    console.error(e);
                }
                // don't drop onto parent menu's as well:
                event.stopPropagation();
            });
            // or forget about the dropping and remove the stuff
            el.addEventListener('mouseout', function () {
                const el = document.getElementById('sub_menu_item_drop');
                if (el) {
                    this.classList.remove('dragover');
                    el.remove();
                }
            });
        }
    } else {
        console.error('This is not a menu_item:');
        console.log(row);
        el.innerHTML = 'ERROR';
    }
    this.DOMElement = el;
}
const PEATCMS_admin_menu = function (menu) {
    const el = document.createElement('ul');
    let i, len, items;
    if (menu.hasOwnProperty('__item__')) {
        items = menu.__item__;
        for (i = 0, len = items.length; i < len; ++i) {
            el.insertAdjacentElement('beforeend', new PEATCMS_admin_menu_item(items[i], true).DOMElement);
        }
    } else {
        el.insertAdjacentHTML('afterbegin', 'Well that didn’t work.');
    }
    this.DOMElement = el;
}

// TODO a lot of duplicate code from PEATCMS_actor, I think they can eventually be merged into something more universal
// TODO maybe create a class with most of the functionality they both can extend
const PEATCMS_column_updater = function (DOMElement, PEATElement) {
    let value, i, len;
    this.DOMElement = DOMElement;
    this.DOMElement.actor = this;
    this.parent_element = PEATElement || null;
    this.column_name = DOMElement.getAttribute('data-column_name');
    this.table_name = DOMElement.getAttribute('data-table_name');
    this.handle = DOMElement.getAttribute('data-peatcms_handle');
    this.id = parseInt(DOMElement.getAttribute('data-peatcms_id'));
    this.server_value = DOMElement.value;
    if (DOMElement.hasAttribute('id') && DOMElement.getAttribute('id') === 'peatcms_publish') {
        // NOTE Firefox says this is type 'submit' even though it's a button
        DOMElement.addEventListener('click', function () {
            this.actor.update(true);
        });
    } else if (['text', 'password', 'textarea'].includes(DOMElement.type)) {
        DOMElement.addEventListener('keydown', function (event) {
            this.actor.keydown(event);
        });
        DOMElement.addEventListener('keyup', function (event) {
            this.actor.typed(event, DOMElement.type !== 'textarea');
        });
        DOMElement.addEventListener('blur', function (event) {
            this.actor.blur(event);
        });
    } else if (DOMElement.type === 'checkbox') {
        DOMElement.addEventListener('change', function () {
            this.actor.update(this.checked);
        });
        // handle pretty checkboxes nicely:
        if ((value = DOMElement.nextElementSibling).hasAttribute('for')
            && value.getAttribute('for') === DOMElement.id
        ) {
            value.tabIndex = 0;
            value.addEventListener('keydown', function (e) {
                if (' ' === e.key) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.click();
                }
            });
        }
    } else if (DOMElement.type === 'radio') {
        console.error(DOMElement);
    } else if (DOMElement.type === 'select-one') {
        if ((value = DOMElement.getAttribute('data-value'))) {
            for (i = 0, len = DOMElement.options.length; i < len; ++i) {
                if (DOMElement.options[i].value === value) {
                    DOMElement.selectedIndex = i;
                    break;
                }
            }
        }
        DOMElement.addEventListener('change', function () {
            this.actor.update(this.options[this.selectedIndex].value);
        });
    } else if (DOMElement.type === 'button') {
        DOMElement.addEventListener('click', function () {
            const msg = this.getAttribute('data-confirm'),
                handle = this.getAttribute('data-peatcms_handle');
            let do_it = true;
            if (msg !== null) {
                do_it = confirm(msg);
            }
            if (do_it) {
                if (handle === 'new_row') {
                    this.actor.new();
                } else if (handle === 'delete_row') {
                    this.actor.delete();
                } else if (handle === 'update_column' && this.hasAttribute('data-column_value')) {
                    this.actor.update(JSON.parse(this.getAttribute('data-column_value')));
                } else {
                    console.warn(`Did not understand handle ${handle}.`);
                }
            }
        });
    } else {
        console.error(DOMElement.type + ' not recognized as PEATCMS_column_updater.');
    }
}

// TODO duplicate code FROM peat.js PEATCMS_actor...
PEATCMS_column_updater.prototype.typed = function (event, enter_triggers_save = false) {
    this.reflectChanged();
    if (true === enter_triggers_save) {
        if (event.key === 'Enter') {
            //this.DOMElement.blur();
            this.update(this.DOMElement.value);
        }
    }
    if (event.key === 'Escape') {
        this.DOMElement.value = this.server_value;
        this.reflectChanged();
    }
}
PEATCMS_column_updater.prototype.keydown = function (event) {
    // this only handles saving
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
        // Save Function
        //this.DOMElement.blur();
        this.update(this.DOMElement.value);
        event.preventDefault();
        event.stopPropagation();
        event.returnValue = '';
        return false;
    }
    if (event.key === 'Tab' && this.DOMElement.tagName === 'TEXTAREA') {
        event.preventDefault();
        event.stopPropagation();
        event.returnValue = '';
        PEATCMS.insertAtCaret(this.DOMElement, '    '); // insert four spaces in stead of a tab
        return false;
    }
}

PEATCMS_column_updater.prototype.blur = function () {
    this.update(this.DOMElement.value);
}

PEATCMS_column_updater.prototype.update = function (value) {
    const self = this,
        data = {
            'table_name': this.table_name,
            'column_name': this.column_name,
            'id': this.id,
            'value': value
        };
    this.reflectChanged();
    this.DOMElement.classList.add('peatcms_loading');
    NAV.ajax('/__action__/update_column', data, function (data) {
        const el = self.DOMElement,
            pub = document.getElementById('peatcms_publish');
        let msg, parent_element;
        if ((parent_element = self.parent_element)) {
            NAV.admin_uncache_slug(parent_element.state.slug, true); // @since 0.10.4
        }
        el.classList.remove('peatcms_loading');
        if (el.type.toLowerCase() === 'button') {
            msg = el.hasAttribute('data-success') ? el.getAttribute('data-success') : 'OK';
            el.insertAdjacentHTML('afterend', msg);
            el.remove();
        } else if (typeof (value = data[self.column_name]) !== 'undefined') { // it returns the whole row, just grab the right column
            self.set(value);
        } else if ('password' === el.dataset.column_name) {
            self.set('');
            //} else { @since 0.7.7 don’t do anything if the value is not returned properly
            //self.set('');
        }
        // update published status when relevant TODO maybe needs to be more robust
        if (pub) {
            if (data['published'] === true) {
                pub.classList.add('published');
            } else {
                pub.classList.remove('published');
            }
        }
    });
}

PEATCMS_column_updater.prototype.delete = function () {
    const data = {
        'table_name': this.table_name,
        'column_name': 'deleted',
        'id': this.id,
        'value': true
    };
    NAV.ajax('/__action__/update_column', data, function (data) {
        if (data.hasOwnProperty('deleted') && data.deleted === true) {
            // remove row, how to know what the row is? it may not be one (parent) DOMElement...
            // the row structure is in the template somewhere, at least...
            // TODO Joeri this is a shortcut, please remove the row without refreshing the whole page
            NAV.refresh();
        } else {
            console.error('Delete row failed.');
        }
    });
}

PEATCMS_column_updater.prototype.new = function () {
    const table_parent = this.DOMElement.getAttribute('data-table_parent'),
        table_parent_id = this.DOMElement.getAttribute('data-table_parent_id'),
        data = {
            'handle': 'insert_row',
            'table_name': this.table_name,
            'where': {
                'parent_id_name': table_parent.replace('_', '') + '_id',
                'parent_id_value': table_parent_id
            }
        };
    NAV.ajax('/__action__/insert_row', data, function (data) {
        // TODO Joeri this is a shortcut, please render a new row without refreshing the whole page
        NAV.refresh();
        if (VERBOSE) console.log(data);
    });
}

PEATCMS_column_updater.prototype.reflectChanged = function () { // TODO integrate with reflectChanged in peat.js
    const el = this.DOMElement;
    if (el.type === 'submit') return;
    if (el.type === 'select-one') {
        if (el.options[el.selectedIndex].value === el.getAttribute('data-value')) {
            el.classList.remove('unsaved');
        } else {
            el.classList.add('unsaved');
        }
    } else {
        if (el.value !== this.server_value.toString()) {
            el.classList.add('unsaved');
        } else {
            el.classList.remove('unsaved');
        }
    }
}

PEATCMS_column_updater.prototype.set = function (value) {
    const el = this.DOMElement;
    // TODO the select-one and checkbox types are not really well implemented
    if (['select-one', 'checkbox'].includes(el.type)) {
        el.setAttribute('data-value', value);
    } else {
        el.value = value;
        this.server_value = value;
    }
    this.reflectChanged();
}

const PEATCMS_panel = function (name, resetStyles) {
    this.name = name; // currently names can be console and sidebar
    // check DOMElement and checkbox TODO you can probably create these yourself as well, when not present
    if (null === (this.DOMCheckbox = document.getElementById(`admin_${name}_checkbox`))) {
        console.error(`Missing checkbox for panel ${name}.`);
    } else {
        this.DOMCheckbox.checked = false; // firefox
        this.DOMCheckbox.addEventListener('change', resetStyles);
    }
    const DOMPanel = document.getElementById(`admin_${name}`);
    if (null === DOMPanel) {
        console.error(`Missing area for panel ${name}.`);
        return;
    }
    if (DOMPanel.querySelector('.edit-area')) {
        DOMPanel.querySelectorAll('.edit-area').forEach(function (el) {
            el.remove();
        });
    }
    const DOMEditElement = document.createElement('div');
    DOMEditElement.className = 'edit-area';
    DOMPanel.insertAdjacentElement('beforeend', DOMEditElement);
    this.DOMEditElement = DOMEditElement;
}

PEATCMS_panel.prototype.open = function () {
    if (this.DOMCheckbox.checked === false) this.DOMCheckbox.click();
}

PEATCMS_panel.prototype.close = function () {
    if (this.DOMCheckbox.checked === true) this.DOMCheckbox.click();
}

PEATCMS_panel.prototype.isOpen = function () {
    return this.DOMCheckbox.checked;
}

PEATCMS_panel.prototype.getDOMElement = function () { // the element holding the html and stuff for editing and such
    return this.DOMEditElement;
}

PEATCMS_panel.prototype.clear = function () { // unused?
    this.DOMEditElement.innerHTML = '';
}

PEATCMS_panel.prototype.getName = function () {
    return this.name;
}

PEATCMS_panel.prototype.getSlug = function () {
    const el = this.DOMEditElement.querySelector('#admin_slug');
    if (el) {
        if (el.value) {
            return el.value;
        } else {
            if (VERBOSE) console.warn('Slug found but no value.');
        }
        console.error(el);
    } else {
        if (VERBOSE) console.warn('Could not find slug in the panel.');
    }
    return null;
}

function PEATCMS_panels() {
    let i, panel_name;
    // in the arguments are the panels provided, these rely on a certain standard id + checkbox convention
    // initialize each one as well as some standard properties
    this.panels = {};
    for (i in arguments) {
        if (arguments.hasOwnProperty(i)) {
            panel_name = arguments[i];
            // ATTENTION: the reset tools is added as an event to the changing of panels
            this.panels[panel_name] = new PEATCMS_panel(panel_name, this.resetTools);
        }
    }

    function addResizer(panel, mover) {
        // resize is separate for now
        const positioner = document.getElementById(`admin_${panel}_positioner`);
        if (null === positioner) return;
        const css_cursor = 'cursor:' + window.getComputedStyle(positioner).cursor;

        function remover() {
            PEAT.stylesheet.upsertRule('*', null);
            document.removeEventListener('mousemove', mover);
            document.removeEventListener('mouseup', remover);
        }

        positioner.addEventListener('mousedown', function () {
            PEAT.stylesheet.upsertRule('*', css_cursor);
            document.addEventListener('mousemove', mover);
            document.addEventListener('mouseup', remover);
        });
    }

    addResizer('sidebar', function (e) {
        e.preventDefault();
        document.documentElement.style.setProperty('--admin-sidebar-width', e.clientX + 'px');
    });
    addResizer('console', function (e) {
        e.preventDefault();
        const h = window.innerHeight;
        document.documentElement.style.setProperty('--admin-console-height', (h + 4 - e.clientY) + 'px');
    });
}

PEATCMS_panels.prototype.open = function (name) {
    let panel_name;
    for (panel_name in this.panels) {
        if (panel_name === name) {
            this.panels[panel_name].open();
        } else {
            this.panels[panel_name].close();
        }
    }
}

// name default '' means close all, withPreserve = remember current state for restore
PEATCMS_panels.prototype.close = function (name = '', withPreserve = false) {
    if (withPreserve === true) this.preserve();
    if (this.panels[name]) {
        this.panels[name].close();
    } else {
        this.open(name); // effectively closes everything, since the panel doesn't exist
    }
}

// PEATCMS_panels.prototype.preserve = function () { // stores state of panels
//     let state = {}, panel, panel_name;
//     for (panel_name in this.panels) {
//         panel = this.panels[panel_name];
//         state[panel_name] = {'open': panel.isOpen(), 'slug': panel.getSlug(),};
//     }
//     console.log(JSON.stringify(state));
//     PEAT.setSessionVar('admin_panels', JSON.stringify(state));
// }
//
// PEATCMS_panels.prototype.restore = function () { // sets panels to previously saved state
//     const state = PEAT.getSessionVar('admin_panels');
//     console.warn(state);
// }

PEATCMS_panels.prototype.toggle = function (name = '') {
    const panel = this.panels[name];
    if (panel) {
        if (panel.isOpen()) {
            panel.close();
        } else {
            this.open(name);
        }
    }
}

PEATCMS_panels.prototype.current = function () {
    let panel;
    for (panel in this.panels) {
        if (this[panel].isOpen()) return panel;
    }
}

PEATCMS_panels.prototype.get = function (panel_name) {
    if (this.panels[panel_name]) {
        return this.panels[panel_name];
    } else {
        if (VERBOSE) console.warn(`Panel ${panel_name} not found`);
        return null;
    }
}


PEATCMS_panels.prototype.resetTools = function () { // make sure the panels are shown
    if (typeof CMS_admin === 'object') {
        CMS_admin.toggleTools(true);
        //CMS_admin.setStylesheet();
    }
}

function PEATCMS_quickie(quickie) {
    // preliminary sanity check of the quickie, eg it can have at most 1 file, and it must have an element
    if (false === quickie.hasOwnProperty('element')) {
        return this.configError('Quickie must have an element.');
    }
    if (false === quickie.hasOwnProperty('fields')
        || false === Array.isArray(quickie.fields)
        || 1 < quickie.fields.filter(function (field) {
            return field.hasOwnProperty('type') && 'file' === field.type;
        }).length) {
        return this.configError('Quickie must have fields of which at most 1 is a file.');
    }
    // save clean config
    delete quickie.slug; // you may not specify a slug in the config
    this.initializing_element = false;
    this.config = quickie;
    this.state = null; // the element as returned by the server
    this.file = null; // the file input, with .peatcms_quickie_field set as config
    this.startUp();
    return true;
}

PEATCMS_quickie.prototype.startUp = function () {
    // open a full screen modal
    const modal = document.createElement('div'),
        quickie = this,
        config = this.config,
        button = document.createElement('button'),
        title = document.createElement('h3'),
        close = document.createElement('div'),
        form = document.createElement('form');
    // fix the body from scrolling
    PEAT.html_node.setAttribute('data-has-modal', '1');
    // build the modal
    modal.id = 'quickie-modal';
    modal.classList.add('PEATCMS_admin');
    modal.appendChild(form);
    close.classList.add('close', 'button');
    close.innerText = '×';
    close.addEventListener('click', function () {
        PEAT.html_node.removeAttribute('data-has-modal');
        modal.remove();
    });
    modal.appendChild(close);
    title.appendChild(document.createTextNode(config.label || 'Quickie'));
    title.classList.add('divider');
    form.appendChild(title);
    // create a form with the fields from config.fields
    // the file field will start uploading immediately and create an element on the server with that file and default title
    // and display the file when uploaded in this form
    for (const field of config.fields) {
        const label = document.createElement('label'),
            wrap = document.createElement('div');
        label.appendChild(document.createTextNode(field.label || ''));
        wrap.classList.add('wrap');
        wrap.appendChild(label);
        let input;
        if ('textarea' === field.type) {
            input = document.createElement('textarea');
        } else {
            input = document.createElement('input');
            input.type = field.type || 'text';
        }
        input.placeholder = field.placeholder || '';
        delete field.state; // you may not specify a state in the config
        input.peatcms_quickie_field = field;
        if ('file' === input.type) {
            // quick sanity check for file type
            const type_name = field.element || quickie.config.element;
            if (false === ['image', 'file'].includes(type_name)) {
                return quickie.configError('Quickie element must be file or image when a file input is supplied.');
            }
            // ok
            quickie.file = input;
            input.addEventListener('change', function () {
                quickie.saveFile(this);
            });
        } else {
            input.addEventListener('change', function () {
                quickie.save(field.column, this);
            });
            // @since 0.26.0 keep saving at regular intervals
            const peatcms_quickie_interval = setInterval(function (input) {
                if (false === document.body.contains(input)) {
                    clearInterval(peatcms_quickie_interval);
                    return;
                }
                quickie.save(field.column, input);
            }, 5001, input);
        }
        wrap.appendChild(input);
        form.appendChild(wrap);
    }

    // add submit button, that sends the data to the server including config.properties
    // and links the file if necessary
    button.type = 'submit';
    button.setAttribute('id', 'quickie-publish');
    button.setAttribute('disabled', 'disabled'); // until an element is created (quickie has state)
    button.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        this.setAttribute('disabled', 'disabled');
        const type_name = quickie.config.element;

        function publish() {
            const config = quickie.config;
            NAV.ajax('/__action__/admin_publish_element', {
                element: type_name,
                id: quickie.state[`${type_name}_id`],
                template_name: config.template || null,
                css_class: config.css_class || null,
                properties: config.properties || {}
            }, function (data) {
                if (true === data.success) {
                    NAV.go(data.slug);
                } else {
                    PEAT.message('Failed to publish.', 'error');
                }
            });
        }

        // link the file if necessary, before publishing
        if (undefined !== quickie.file.peatcms_quickie_state) {
            const linkable_link = quickie.file.peatcms_quickie_state;
            if (VERBOSE) console.log('Linking file.');
            NAV.ajax('/__action__/admin_linkable_link', {
                element: type_name,
                id: quickie.state[`${type_name}_id`],
                sub_element: linkable_link.type_name,
                sub_id: linkable_link[`${linkable_link.type_name}_id`]
            }, function (json) {
                if (false === json.success) {
                    PEAT.message('Failed to link file.', 'error');
                } else {
                    publish();
                }
            });
        } else {
            publish();
        }

        // link to parent element if required
        if (quickie.config.hasOwnProperty('parent')) {
            const parent = quickie.config.parent;
            NAV.ajax('/__action__/admin_linkable_slug', {
                slug: parent.slug,
                total: parent.hasOwnProperty('total') ? parseInt(parent.total) : null,
                place: parent.hasOwnProperty('place') ? parseInt(parent.place) : null,
                element: type_name,
                id: quickie.state[`${type_name}_id`]
            }, function (json) {
                if (false === json.success) {
                    PEAT.message('Failed to link to parent.', 'error');
                }
            });
        }
    });
    form.appendChild(button);
    // add to dom
    requestAnimationFrame(function () {
        const el = document.getElementById('quickie-modal');
        if (el) el.remove();
        document.body.appendChild(modal);
        PEAT.addEventListener('peatcms.navigation_start', function () {
            PEAT.html_node.removeAttribute('data-has-modal');
        }, true);
    });
}

PEATCMS_quickie.prototype.save = function (column, input, force) {
    const self = this,
        data_value = input.getAttribute('data-value') || '',
        element_name = this.config.element;
    let value = PEATCMS.trim(input.value);
    // don’t save if nothing happened (yet)
    if (true !== force && value === data_value) return;
    // File is saved independently. Make sure to use one slug only for saving.
    if (null === self.state) {
        self.initializeElement(function () {
            input.dispatchEvent(new Event('change'));
        });
        return;
    }
    input.setAttribute('data-value', value);
    if (VERBOSE) console.log('Auto save', column);
    if (true === Array.isArray(column)) { // a field can update multiple columns
        for (const col of column) {
            self.save(col, input, true);
        }
        return;
    }
    // truncate title to avoid all kinds of failures on the server and it not being saved
    if ('title' === column) {
        value = PEATCMS.replace('\n', ' ', value);
        if (value.length > 127) {
            let pos = value.lastIndexOf(' ', 124);
            if (pos < 0) pos = 124; // just break at the end
            value = value.substring(0, pos) + '...';
        }
    }
    NAV.ajax('/__action__/update_column', {
        'table_name': `cms_${element_name}`,
        'column_name': column,
        'value': value,
        'id': self.state[`${element_name}_id`]
    }, function (data) {
        if (data.hasOwnProperty(column)) {
            self.state = data;
        } else {
            console.error('Fail', data);
        }
    });
}

PEATCMS_quickie.prototype.saveFile = function (input) {
    const file = input.files[0],
        field = input.peatcms_quickie_field,
        self = this;
    if ('undefined' === typeof file) return; // when canceled
    let slug = null;
    input.setAttribute('disabled', 'disabled');
    if (true === field.hasOwnProperty('element')) {
        slug = field.slug || null;
    } else if (null === self.state) {
        self.initializeElement(function () {
            input.dispatchEvent(new Event('change'));
        });
        return;
    } else {
        slug = self.state.slug;
    }
    self.showImage();
    NAV.fileUpload(function (data) {
        if (data.hasOwnProperty('filename_saved')) {
            PEAT.message('File uploaded successfully.');

            function process() {
                if ('image' === data.type_name) {
                    const level = field.level || 1;
                    NAV.ajax(`/__action__/process_file/level:${level}/slug:${data.slug}`);

                    function showImage(json) {
                        if (!json.hasOwnProperty('src_large')) {
                            console.error('No src_large in json.', json);
                        } else {
                            if (true === self.showImage(json.src_large)) {
                                CMS_admin.unsubscribe('image', json.image_id, showImage);
                            }
                        }
                    }

                    CMS_admin.subscribe('image', data.id, showImage);
                } else if (data.hasOwnProperty('content_type') && 'application/pdf' === data.content_type) {
                    // show pdf inside img div
                    const el = document.getElementById('quickie-image');
                    if (el && data.hasOwnProperty('slug')) {
                        el.innerHTML = `<object data="/__action__/download/${data.slug}" type="application/pdf" width="100%" height="100%">Ready: <a href="/__action__/download/${data.slug}">download</a></object>`;
                        el.style.backgroundImage = 'none';
                    }
                }
            }

            // if this is a separate element from config.element, update file state
            if (field.hasOwnProperty('element')) {
                field.slug = data.slug;
                input.peatcms_quickie_state = data; // remember for linking later
                // property css_class is allowed on separate file element like on main element
                // update it before processing so it does not override the automated css classes
                if (field.hasOwnProperty('css_class')) {
                    NAV.ajax('/__action__/update_column', {
                        table_name: data.table_name,
                        column_name: 'css_class',
                        value: field.css_class,
                        id: data.id
                    }, process);
                } else {
                    process();
                }
                // set online property for this element as desired, offline may be required (e.g. ruigehond)
                if (false === field.hasOwnProperty('online') || true === field.online) {
                    NAV.ajax('/__action__/update_column', {
                        table_name: data.table_name,
                        column_name: 'online',
                        value: true,
                        id: data.id
                    });
                }
            } else {
                self.state = data;
                process();
            }
            input.removeAttribute('disabled');
        } else {
            console.error('Fail', data);
            PEAT.message('Fail', 'error');
            input.removeAttribute('disabled');
        }
    }, file, slug);
}

PEATCMS_quickie.prototype.showImage = function (src) {
    if (!this.file) {
        console.error('Quickie has no file input to show image for.');
        return false;
    }
    let img = document.getElementById('quickie-image');
    if (!img) {
        img = document.createElement('div');
        img.classList.add('image');
        img.setAttribute('id', 'quickie-image');
        this.file.insertAdjacentElement('beforebegin', img);
    }
    return 0 !== requestAnimationFrame(function () {
        if (!src) {
            src = '/_front/logo-anim.gif';
            img.style.backgroundSize = 'auto';
        } else {
            img.style.backgroundSize = 'contain';
        }
        img.style.backgroundImage = `url(${src})`;
    });
}

PEATCMS_quickie.prototype.configError = function (msg) {
    console.error(msg);
    PEAT.message('Config error for quickie.', 'error');
    return false;
}

PEATCMS_quickie.prototype.isInitializingElement = function () {
    return this.initializing_element;
}

PEATCMS_quickie.prototype.initializeElement = function (callback) {
    if (true === this.initializing_element) {
        console.warn('Already initializing element.');
        setTimeout(callback, 309);
        return;
    }
    this.initializing_element = true;
    const self = this;
    NAV.ajax('/__action__/create_element', {
        element: this.config.element,
        online: false,
    }, function (json) {
        console.warn(json);
        if (json.hasOwnProperty('slug')) {
            self.state = json;
            self.initializing_element = false;
            if (callback) callback();
            document.getElementById('quickie-publish').removeAttribute('disabled');
        } else {
            console.error('Failed to initialize element.');
        }
    });
}

/**
 * PEATCMS_admin object
 */
function PEATCMS_admin() {
    const self = this;
    let nodes, node, i, len, element_name, cell, hidden_cells, style;
    this.elements = {};
    this.instance = null;
    this.orders = {};
    this.poll_timeout_ms = 5004;
    this.poll_timeout = null;
    this.editor_config = this.loadEditorConfig();
    this.quickies = this.loadQuickies();
    this.subscriptions = [];
    // the two edit regions (panels) currently used are 'console' (always bottom) and 'sidebar' (always left)
    this.panels = new PEATCMS_panels('console', 'sidebar');
    // setup some admin properties that manage the editing tools
    this.CSSClass = 'PEATCMS_admin';
    //document.getElementsByTagName('html')[0].id = 'ADMIN';
    // add a style to manipulate on the fly, not linked to other stylesheets
    style = document.createElement('style');
    // style.setAttribute("media", "screen")
    // style.setAttribute("media", "only screen and (max-width : 1024px)")
    style.appendChild(document.createTextNode('')); // WebKit hack :(
    style.id = 'peatcms_dynamic_admin_css';
    document.head.appendChild(style);
    this.stylesheet = new PEAT_style(style.sheet);
    // get instance for global settings (like homepage)
    NAV.ajax(
        '/__admin__/instance/',
        false,
        function (data) {
            if (data.hasOwnProperty('table_name') && data.table_name === '_instance') {
                self.instance = data;

                // Toggles (may) use instance, so we moved them here
                function enhanceToggles() {
                    CMS_admin.enhanceToggle(document.querySelectorAll('#PEATCMS_admin_page .toggle_button'));
                }

                if (PEAT.document_status >= PEAT.status_codes.ready) {
                    enhanceToggles();
                }
                document.addEventListener('peatcms.document_ready', enhanceToggles);
                document.addEventListener('peatcms.progressive_ready', function (e) {
                    const detail = e.detail, parent_element = e.target.parentNode;
                    if (detail.hasOwnProperty('slug')) {
                        CMS_admin.enhanceToggle(parent_element.querySelectorAll('.toggle_button'));
                    }
                });
            } else {
                console.error('Failed to get current instance.');
            }
        });
    /**
     * set up the buttons / admin interface
     */
    // for each button, create a 'new' request to the server, and redirect to the new element
    nodes = document.querySelectorAll('button[data-action="new"]');
    for (i = 0, len = nodes.length; i < len; ++i) {
        node = nodes[i];
        if (node.getAttribute) {
            if ((element_name = node.getAttribute('data-element_name'))) {
                node.addEventListener('mouseup', function () {
                    self.createElement(this.getAttribute('data-element_name'));
                });
            }
        }
    }
    // add search boxes for elements in the console
    nodes = document.querySelectorAll('div[data-action="search"]');
    hidden_cells = (this.getEditorConfig('console')).hidden_fields || {};

    function activate_cell() {
        if (this.classList.contains('active')) return;
        // remove the .active from all harmonicas
        document.querySelectorAll('#admin_console .cell.harmonica').forEach(
            function (el) {
                el.classList.remove('active');
                // while tabbing the headers may scroll internally, reset them here
                try {
                    el.querySelector('header').scrollTo(0, 0);
                } catch (e) {
                    console.error(e);
                }
            }
        );
        // add it to this one
        this.classList.add('active');
    }

    for (i = 0, len = nodes.length; i < len; ++i) {
        node = nodes[i];
        // the cell they're in should be small / hidden, unless you're working in this one
        cell = node.closest('.cell');
        if (node.hasAttribute('data-element_name')
            && hidden_cells.hasOwnProperty(node.getAttribute('data-element_name'))
        ) {
            cell.remove();
            continue;
        }
        cell.classList.add('harmonica');
        cell.addEventListener('mousedown', activate_cell);
        cell.addEventListener('focusin', activate_cell);
        // TODO quick workaround with the id here, but need to rework to proper callback and use of 'this' and stuff
        node.id = `PEATCMS_console_search_${node.getAttribute('data-element_name')}`;
        node.insertAdjacentElement('beforebegin',
            new PEATCMS_searchable(
                node.getAttribute('data-element_name'),
                function (element, rows) {
                    // remove the children, and add the returned rows as children (as links)...
                    const list_el = document.getElementById(`PEATCMS_console_search_${element}`);
                    let row_i, row, row_len, el, btn;
                    list_el.innerHTML = '';
                    for (row_i = 0, row_len = rows.length; row_i < row_len; ++row_i) {
                        if (rows.hasOwnProperty(row_i)) {
                            row = rows[row_i];
                            el = document.createElement('div');
                            el.classList.add('peatcms-link', row.online ? 'online' : 'offline');
                            el.setAttribute('data-href', `/${row.slug}`);
                            el.insertAdjacentText('afterbegin', row.title);
                            el.onclick = function (e) {
                                e.preventDefault();
                                e.stopPropagation();
                                if (e.ctrlKey || e.metaKey) {
                                    window.open(this.getAttribute('data-href'));
                                } else {
                                    NAV.go(this.getAttribute('data-href'));
                                }
                            };
                            btn = document.createElement('button');
                            btn.className = 'edit';
                            btn.insertAdjacentHTML('afterbegin', 'E');
                            btn.onclick = function (event) {
                                self.panels.open('sidebar'); // the occuring navigation will then trigger edit
                            };
                            el.insertAdjacentElement('afterbegin', btn);
                            list_el.insertAdjacentElement('beforeend', el);
                        }
                    }
                }).DOMElement);
    }

    function activate() {
        let el, inputs, style;
        // set homepage button
        document.querySelectorAll('button[data-peatcms_handle="set_homepage"]').forEach(
            function (btn) { //, key, parent) {
                if (btn.hasAttribute('data-peatcms_active')) return;
                btn.setAttribute('data-peatcms_active', '1');
                btn.addEventListener('click', function () {
                    self.setHomepage();
                });
            }
        );
        // automate status of homepage button and set some other buttons
        self.setHomepageButtonStatus();
        // enable / disable the standard css
        if ((el = document.getElementById('bloembraaden-css'))) {
            if (document.getElementById('PEATCMS_admin_page')) {
                el.setAttribute('rel', 'alternate');
                if ((el = document.getElementById('bloembraaden-default-css'))) {
                    el.setAttribute('rel', 'stylesheet');
                } else {
                    style = document.createElement('link');
                    style.setAttribute('id', 'bloembraaden-default-css');
                    style.setAttribute('href', `/_front/peat.css?version=${PEATCMS_globals.version || Math.random()}`);
                    style.setAttribute('rel', 'stylesheet');
                    document.head.appendChild(style);
                }
            } else {
                el.setAttribute('rel', 'stylesheet');
                if ((el = document.getElementById('bloembraaden-default-css'))) {
                    el.setAttribute('rel', 'alternate');
                }
            }
        }
        document.querySelectorAll('[data-peatcms_handle="edit_current"]').forEach(
            function (btn) {
                btn.onclick = function () {
                    CMS_admin.edit()
                };
            }
        );
        document.querySelectorAll('[data-peatcms_handle="uncache_current"]').forEach(
            function (btn) {
                btn.onclick = function () {
                    NAV.admin_uncache_slug()
                };
            }
        );
        document.querySelectorAll('[data-peatcms_handle="send_email"]').forEach(
            function (btn) {
                // TODO integrate turnstile...
                btn.onclick = function () {
                    NAV.ajax(
                        '/__action__/sendmail/',
                        {to: this.previousElementSibling.value},
                        function (json) {
                            PEAT.message(json.message);
                        });
                };
            }
        );
        document.querySelectorAll('[data-peatcms_handle="cancel_order"]').forEach(
            function (btn) {
                if (btn.hasAttribute('data-order_id')) {
                    btn.onclick = function () {
                        let submit_msg;
                        if (this.getAttribute('data-confirm') &&
                            null !== (submit_msg = this.getAttribute('data-confirm'))
                        ) {
                            if (false === confirm(submit_msg)) {
                                return false;
                            }
                        }
                        NAV.ajax(
                            '/__action__/cancel_order',
                            {order_id: parseInt(this.getAttribute('data-order_id'))},
                            function (json) {
                                if (json.hasOwnProperty('success') && true === json.success) {
                                    PEAT.message('Order canceled.');
                                } else {
                                    console.error(json);
                                }
                            }
                        );
                    };
                } else {
                    console.error('cancel_order button needs data-order_id to function');
                }
            }
        );
        inputs = document.querySelectorAll('.admin_order_search');
        // search order forms (can have multiple on the page)
        inputs.forEach(function (input) {
            input.onkeyup = function (e) {
                if (e.key === 'Enter') {
                    NAV.go(`/__order__/${input.value}`);
                }
            }
        });
        if ((el = document.getElementById('payment_link'))) {
            el.addEventListener('click', function () {
                const payment_link = NAV.root + PEATCMS.replace(' ', '', this.getAttribute('data-href'));
                if (PEAT.copyToClipboard(payment_link)) {
                    PEAT.message('Link copied to clipboard.');
                }
            });
        }
        document.querySelectorAll('.session_destroy').forEach(function (el) {
            if (el.hasAttribute('data-peatcms_active')) return;
            el.setAttribute('data-peatcms_active', '1');
            el.addEventListener('peatcms.form_posted', function (e) {
                if (e.detail.json.success) {
                    this.innerHTML = 'Marked for destruction.';
                }
            });
        });
        document.querySelectorAll('[type="file"]').forEach(function (el) {
            if (el.hasAttribute('data-peatcms_active')) return;
            el.setAttribute('data-peatcms_active', '1');
            const form = el.form;
            //const label = el.label;
            if (!form) {
                console.error('File input element has no form.', el);
                return;
            } else if (form.querySelectorAll('[type="file"]').length > 1) {
                console.error('Form has more than one file input element', form);
            }
            form.addEventListener('peatcms.form_posting', function (e) {
                const file_input = this.querySelector('[type="file"]');
                if (!file_input) return; // let the form handle itself
                this.removeAttribute('data-submitting'); // cancel submission and handle file upload first
                if (!file_input.value) {
                    PEAT.message('No file selected.');
                    return;
                }
                // NAV.fileupload first
                NAV.fileUpload(function (data) {
                    if (data.hasOwnProperty('file_saved')) {
                        PEAT.message('File uploaded successfully.');
                        file_input.type = 'button';
                        file_input.value = '...';
                        file_input.onclick = function (e) {
                            this.type = 'file';
                        };
                        form.dispatchEvent(new Event('submit'));
                    } else {
                        console.error('Fail', data);
                        PEAT.message('Fail', 'error');
                    }
                }, file_input.files[0], null, file_input);
                // only then submit the form proper, with the filename in the form
            });
        });
        // for paging, set proximity
        document.querySelectorAll('.PEATCMS_admin .paging').forEach(function (div) {
            if (div.hasAttribute('data-peatcms_active')) return;
            div.setAttribute('data-peatcms_active', '1');
            const nodes = div.querySelectorAll('a'),
                len = nodes.length;

            function act(self, hover) {
                const nodes = self.parentNode.querySelectorAll('a'),
                    len = nodes.length;
                let current = 0, hovered = null, i;
                for (i = 0; i < len; ++i) {
                    const node = nodes[i];
                    if (hover && self === node) {
                        hovered = i;
                    }
                    if (node.classList.contains('peatcms-current-slug')) {
                        current = i;
                        if (null === hovered) hovered = i;
                    }
                }
                if (null === hovered) hovered = current;
                for (i = 0; i < len; ++i) {
                    const dist = Math.min(Math.abs(i - current), Math.abs(i - hovered));
                    nodes[i].setAttribute('data-page-distance', dist.toString());
                    nodes[i].setAttribute('data-i-h-c', `${i} ${hovered} ${current}`);
                }
            }

            function hover(which) {
                act(which, true);
            }

            function unhover(which) {
                act(which);
            }

            for (let i = 0; i < len; ++i) {
                nodes[i].addEventListener('mouseover', function () {
                    hover(this);
                });
                nodes[i].addEventListener('mouseout', function () {
                    unhover(this);
                });
            }
        });
        // setup the quickies
        if ((el = document.getElementById('quickies'))) {
            el.innerHTML = '';
            for (const quickie of self.quickies) {
                const li = document.createElement('li');
                li.innerHTML = quickie.label || 'Label';
                li.addEventListener('click', function () {
                    // to prevent memory leaks attach the quickie to the element
                    this.peatcms_quickie = new PEATCMS_quickie(quickie);
                });
                el.appendChild(li);
            }
        }
        // setup menu editor
        if ((el = document.getElementById('PEATCMS_admin_menu_editor'))) self.startMenuEditor(el);
        self.orderRatingGrid();
    }

    document.addEventListener('peatcms.document_ready', activate);
    if (PEAT.document_status >= PEAT.status_codes.ready) {
        activate();
    }

    window.addEventListener('keyup', function (event) {
        let els;
        if (event.key === 'Control') {
            if ((els = document.querySelectorAll('.peatcms_ctrl_key_tip'))) {
                els.forEach(function (el) {
                    el.remove();
                });
            }
        }
    });
    window.addEventListener('keydown', function (event) {
        let els;
        // ctrl+, = toggle edit, ctrl+. = toggle console, ctrl+/ = show / hide tools
        if (event.key === 'Control') {
            if (0 < document.getElementsByClassName('peatcms_ctrl_key_tip').length) return;
            if ((els = document.querySelectorAll('[data-ctrl_key]'))) {
                els.forEach(function (el) {
                    const tip = document.createElement('div');
                    tip.className = 'peatcms_ctrl_key_tip';
                    tip.appendChild(document.createTextNode(el.getAttribute('data-ctrl_key')));
                    el.insertAdjacentElement('afterbegin', tip);
                    if (tip.getBoundingClientRect().left > window.innerWidth - 100) {
                        tip.style.right = '0';
                    }
                });
            }
        }
        if (event.ctrlKey || event.metaKey) {
            if (event.key === '/') {
                self.toggleTools();
            } else if (event.key === ',') {
                const path = NAV.getCurrentPath();
                if (path === self.panels.get('sidebar').getSlug()) {
                    self.panels.toggle('sidebar');
                } else {
                    self.edit(path); // (path)
                }
            } else if (event.key === '.') {
                self.panels.toggle('console');
            }
        }
        return true;
    });
    // get the news from the server
    this.poll_timeout = setTimeout(this.pollServer, this.poll_timeout_ms * 2);
    window.addEventListener('focus', self.pollServer);
    //
    if (VERBOSE) console.log('... peatcms admin activated.');
    // into edit mode if requested TODO use panels.restore() or maybe something the user can set
    //if (PEAT.getSessionVar('editing') === true) self.edit();
}

PEATCMS_admin.prototype.orderRatingGrid = function () {
    // setup thumbs rating on order overview page
    document.querySelectorAll('.order[data-rating]:not([data-rating=\'\'])').forEach(function (el) {
        // rotate the thumb conforming the rating :-D
        const rating = parseFloat(el.getAttribute('data-rating') || 0),
            thumb = el.querySelector('.rating');
        if (thumb) thumb.style.transform = `rotateZ(${(1 - rating) * 180}deg)`; // duplicate
    });
}
PEATCMS_admin.prototype.orderRatingDetail = function (row) {
    const thumb = row.querySelector('.rating'),
        rating = parseFloat(row.getAttribute('data-rating') || 0);
    thumb.style.transform = `rotateZ(${(1 - rating) * 180}deg)`; // duplicate
}


PEATCMS_admin.prototype.pollServer = function () {
    if (false === document.hasFocus()) return;
    const self = CMS_admin, timestamp = self.polled_until || Math.floor(NAV.nav_timestamp / 1000);
    NAV.ajax(`/__action__/poll/from:${timestamp}`, {peatcms_ajax_config: {track_progress: false}}, function (json) {
        let el, handled = 0;
        if (false === json.is_admin && (el = document.getElementById('admin_wrapper'))) {
            el.remove();
            PEAT.message('Admin logged out.', 'warn');
            return;
        }
        self.polled_until = json.until;
        // get info
        const changes = json.changes || [], len = changes.length;
        for (let i = 0; i < len; ++i) {
            const change = changes[i];
            if ('_order' === change.table_name) {
                const order_id = change.key;
                handled++;
                NAV.ajax('/__action__/admin_get_element', {
                    element: 'order',
                    id: order_id
                }, function (json) {
                    const current_state = NAV.getCurrentElement().state;
                    if (current_state.hasOwnProperty('__orders__')) {
                        const el = document.getElementById(`order-${order_id}`),
                            template = PEAT.templates['order_overview_true'] || undefined,
                            row_template = template.template.__orders__[0] || undefined;
                        if (!template || !row_template) {
                            console.warn('Template order_overview_true or row not present, cannot render.');
                            return;
                        }
                        const row_html = template.convertTagsRemaining(template.renderOutput(json, row_template));
                        //console.warn('koekkoek', template, row_html, json);
                        if (el) { // if the order is on the page, update it
                            el.insertAdjacentHTML('afterend', row_html);
                            el.remove();
                        } else { // if not, just add it at the top of the list below the header, as a service
                            try {
                                document.querySelector('.order-table').querySelector('.header').insertAdjacentHTML('afterend', row_html);
                            } catch (e) {
                                console.error(e);
                            }
                        }
                        requestAnimationFrame(function () {
                            self.orderRatingDetail(document.getElementById(`order-${order_id}`));
                        });
                    } else if (current_state.hasOwnProperty('__order__') && current_state.order_id === change.key) {
                        // update the detail view
                        NAV.refresh();
                    } else {
                        // TODO
                        console.log(`Order status update for ${json.order_number_human}`);
                    }
                });
            } else { // handle the subscriptions
                if (9 < handled) {
                    console.warn('Too many changes handled, skipping further updates.');
                    continue;
                }
                // TODO
                console.log(`Update for ${change.table_name} (${change.key})`);
                let type_name = change.table_name;
                if ('cms_' === type_name.substring(0, 4)) type_name = type_name.substring(4);
                if ('_' === type_name.substring(0, 1)) type_name = type_name.substring(1);
                for (const entry of self.subscriptions) {
                    if (type_name === entry.type_name && change.key === entry.id) {
                        handled++;
                        NAV.ajax('/__action__/admin_get_element', {
                            element: type_name,
                            id: change.key
                        }, function (json) {
                            entry.callback(json);
                        });
                    }
                }
            }
            //if (NAV.getCurrentElement())
        }
        // repeat...
        clearTimeout(self.poll_timeout);
        self.poll_timeout = setTimeout(self.pollServer, self.poll_timeout_ms);
    }, 'GET');
}
PEATCMS_admin.prototype.subscribe = function (type_name, id, callback) {
    const entry = {type_name: type_name, id: id, callback: callback};
    if (this.subscriptions.filter(function (entry) {
        return entry.type_name === type_name && entry.id === id && entry.callback === callback;
    }).length > 0) {
        if (VERBOSE) console.log('Already subscribed.', entry);
        return;
    }
    this.subscriptions.push(entry);
}
PEATCMS_admin.prototype.unsubscribe = function (type_name, id, callback) {
    this.subscriptions = this.subscriptions.filter(function (entry) {
        return !(entry.type_name === type_name && entry.id === id && entry.callback === callback);
    });
}

/**
 * Editor Configuration
 */
PEATCMS_admin.prototype.loadEditorConfig = function () {
    let default_config = {}, custom_config;
    if ('undefined' !== typeof peatcms_editor_config && peatcms_editor_config.hasOwnProperty('editor')) {
        default_config = peatcms_editor_config.editor;
        if ('undefined' !== typeof peatcms_editor_config_custom && peatcms_editor_config_custom.hasOwnProperty('editor')) {
            custom_config = peatcms_editor_config_custom.editor;
            // merge hidden fields first, because the ones in the default config should be preserved @since 0.11.1
            if (custom_config.hasOwnProperty('hidden_fields') && default_config.hasOwnProperty('hidden_fields')) {
                custom_config.hidden_fields = Object.assign(custom_config.hidden_fields, default_config.hidden_fields);
            }
            default_config = Object.assign(default_config, custom_config);
        }
    }
    return default_config;
}
PEATCMS_admin.prototype.getEditorConfig = function (element_name) {
    const config = PEATCMS.cloneStructured(this.editor_config);
    let type_config;
    if (config.hasOwnProperty(element_name) && (type_config = config[element_name])) {
        if (type_config.hasOwnProperty('hidden_fields')) {
            config.hidden_fields = Object.assign(config.hidden_fields, type_config.hidden_fields);
        }
        if (type_config.hasOwnProperty('field_order')) {
            config.field_order = type_config.field_order;
        }
    }
    return config;
}

PEATCMS_admin.prototype.loadQuickies = function () {
    if ('undefined' !== typeof peatcms_editor_config_custom && peatcms_editor_config_custom.hasOwnProperty('quickies')) {
        return peatcms_editor_config_custom.quickies;
    }
    return [];
}

/**
 * Toggles all admin tools in DOM between display: inherit and display: none
 * if you provide 'open' as true, it will (leave) open the tools in all cases
 * @param open boolean: force open (true) or close (false) state
 */
PEATCMS_admin.prototype.toggleTools = function (open = null) {
    let display_value = 'none',
        current_value = (this.stylesheet.getCurrentValue(`.${this.CSSClass}`, 'display'));
    if (open === true) {
        display_value = 'inherit';
    } else if (open !== false) { // when false display_value should stay 'none'
        if (current_value === 'none') display_value = 'inherit';
    }
    if (current_value !== display_value) {
        this.stylesheet.upsertRule(`.${this.CSSClass}`, `display: ${display_value}`);
    }
}

PEATCMS_admin.prototype.setStyleRule = function (selector, rule) {
    this.stylesheet.upsertRule(selector, rule);
}

/**
 * set the current element as homepage
 */
PEATCMS_admin.prototype.setHomepage = function () {
    const self = this;
    NAV.ajax(
        '/__action__/admin_set_homepage',
        {slug: NAV.getCurrentSlug()},
        function (data) {
            if (data === null) {
                return;
            }
            // expects an instance in return
            if (data.table_name === '_instance') {
                self.instance = data;
                self.setHomepageButtonStatus();
            }
        }
    );
}

/**
 * reads the page and determines whether it is the homepage, if so sets the status of the buttons to linked
 */
PEATCMS_admin.prototype.setHomepageButtonStatus = function () {
    const el = NAV.getCurrentElement(), instance = this.instance, self = this;
    if (!instance || null === el || false === el.hasOwnProperty('state')) {
        PEAT.addEventListener('peatcms.document_complete', function () {
            self.setHomepageButtonStatus();
        }, true);
        return;
    }
    if (el.state.hasOwnProperty('page_id') && el.state['page_id'] === instance['homepage_id']) {
        document.querySelectorAll('button[data-peatcms_handle="set_homepage"]').forEach(
            function (btn) {
                btn.classList.add('linked');
            }
        );
        return;
    }
    document.querySelectorAll('button[data-peatcms_handle="set_homepage"]').forEach(
        function (btn) {
            btn.classList.remove('linked');
        }
    )
}

/**
 * shows the CMS button edit_button over an element to open the edit screen for that element
 * @param DOMElement
 */
PEATCMS_admin.prototype.showEditMenu = function (DOMElement) {
    const rect = DOMElement.getBoundingClientRect(),
        self = this;
    let menu;
    if (!(menu = document.getElementById('PEATCMS_edit_menu'))) {
        menu = document.createElement('div');
        menu.id = 'PEATCMS_edit_menu';
        menu.className = 'PEATCMS_admin';
        menu.innerHTML = '✎';
        document.body.appendChild(menu);
    }
    menu.onclick = function () { // replace any other onclick functions with this one
        self.edit(DOMElement.getAttribute('data-peatcms_slug'));
    };
    menu.style.left = Math.max(0, rect.left) + 'px';
    menu.style.top = Math.max(0, rect.top) + 'px';
}

PEATCMS_admin.prototype.edit = function (slug) {
    const self = this, el = NAV.getCurrentElement();
    //console.error(slug +' vs. '+ el.state.slug);
    if (!slug) {
        if (null === el || false === el.isEditable()) {
            if (VERBOSE) console.error('Current element is not editable.');
            return;
        }
        slug = el.state.slug;
    }
    new PEATCMS_element(slug, function (el) {
        const p = self.panels.get('sidebar'); // el = ‘self’, the PEATCMS_element
        if (el !== false) {
            //self.elements[slug] = el;
            el.edit(p.getDOMElement(), function () {
                self.panels.open('sidebar');
            });
        }
    });
}

PEATCMS_admin.prototype.createElement = function (type) {
    const self = this;
    const online = this.getEditorConfig(type).hidden_fields['online'] || false;
    NAV.ajax(
        '/__action__/create_element',
        {element: type, online: online},
        function (data) {
            if (data.hasOwnProperty('slug')) {
                //self.edit(data.slug);
                self.panels.open('sidebar'); // navigation will load edit_area
                NAV.go(data.slug);
            } else {
                console.warn('Edit for new item failed.');
                if (VERBOSE) console.log(data);
            }
        });
}

// TODO these depend on this page being a menu page etc., this is not very stable and should be refactored entirely
PEATCMS_admin.prototype.startMenuEditor = function (el) {
    if (el.id === 'PEATCMS_admin_menu_editor') {
        const div = document.createElement('div'),
            self = this;
        let findr;
        if (el.hasAttribute('data-peatcms-ajaxified')) return;
        el.setAttribute('data-peatcms-ajaxified', '1');
        NAV.ajax(`/${NAV.getCurrentSlug()}`, false, function (json) {
            // loop through the menu -> item
            if (json.hasOwnProperty('__menu__')) {
                el.insertAdjacentElement('afterbegin', new PEATCMS_admin_menu(json.__menu__).DOMElement);
            } else {
                el.insertAdjacentHTML('afterbegin', '<strong class="soft_error">Could not load menu</strong>');
            }
        });
        // add a toggle area (toggle_menu_item) to delete items or drop the first one if the menu is empty
        div.className = 'toggle drop_area';
        div.innerText = 'Drop item here to toggle on / off from this menu.';
        div.addEventListener('drop', function (event) {
            const dropped_menu_item_id = event.dataTransfer.getData('menu_item_id');
            this.classList.remove('dragover');
            // send to server
            self.putMenuItem({
                menu_item_id: 0, // this will trigger the toggle behaviour on the server
                dropped_menu_item_id: parseInt(dropped_menu_item_id),
                command: 'order',
                menu: NAV.getCurrentSlug(),
            });
            //
            try {
                event.dataTransfer.clearData();
            } catch (e) {
            }
            // don't drop elsewhere:
            event.stopPropagation();
        });
        div.addEventListener('dragover', function (event) {
            event.preventDefault();
            this.classList.add('dragover');
        });
        div.addEventListener('dragleave', function (event) {
            event.preventDefault();
            this.classList.remove('dragover');
        });
        el.insertAdjacentElement('beforeend', div);
        // startup the finder where you can locate all the available items
        if ((findr = document.getElementById('PEATCMS_admin_menu_finder'))) {
            const list_el = document.createElement('ul'),
                node = document.createElement('button');
            list_el.id = 'PEATCMS_menu_item_finder';
            list_el.className = 'results';
            findr.insertAdjacentElement('afterbegin', list_el);
            // TODO this is very similar to the console search box
            findr.insertAdjacentElement('afterbegin', new PEATCMS_searchable('menu_item', function (element, rows) {
                // remove the children, and add the returned rows as children (as links)...
                const list_el = document.getElementById('PEATCMS_menu_item_finder');
                let i, len;
                list_el.innerHTML = '';
                for (i = 0, len = rows.length; i < len; ++i) {
                    list_el.insertAdjacentElement('beforeend', new PEATCMS_admin_menu_item(rows[i]).DOMElement);
                }
            }).DOMElement);
            // new button:
            node.innerHTML = '+';
            node.addEventListener('mouseup', function () {
                NAV.ajax(
                    '/__action__/create_element',
                    {'element': 'menu_item'}, // TODO also send in which menu you would like it to appear
                    function (data) {
                        if (data.hasOwnProperty('slug')) {
                            CMS_admin.edit(data.slug);
                        } else {
                            console.warn('Edit for new item failed');
                            if (VERBOSE) console.log(data);
                        }
                    });
            });
            findr.insertAdjacentElement('beforeend', node);
        } else {
            console.error('Element with id PEATCMS_admin_menu_finder not found.');
        }
    } else {
        console.error('Element with id PEATCMS_admin_menu_editor not found.');
    }
}

PEATCMS_admin.prototype.putMenuItem = function (menu_item_data) {
    NAV.ajax('/__action__/admin_put_menu_item/', menu_item_data, function (json) { // it expects the current menu back
        if (json.hasOwnProperty('slug') && json.slug === NAV.getCurrentSlug()) {
            const div = document.getElementById('PEATCMS_admin_menu_editor'); // TODO maybe you shouldn't have to know how this div is called...
            if (json.hasOwnProperty('__menu__') && div) {
                div.querySelectorAll('ul')[0].remove(); // remove current menu
                div.insertAdjacentElement('afterbegin', new PEATCMS_admin_menu(json.__menu__).DOMElement);
            } else {
                PEAT.message('Menu or edit div not found.', 'error');
            }
        } else {
            PEAT.message('General menu error.', 'error');
        }
    });
}

/**
 * @param elements
 * @since 0.6.8
 */
PEATCMS_admin.prototype.enhanceToggle = function (elements) {
    const self = this;
    if (!self.instance) {
        console.error('enhanceToggle cannot be called before instance is fetched.');
        return;
    }
    elements.forEach(function (el) {
        const toggler = el.parentNode.parentNode,
            toggler_hash = PEATCMS.numericHashFromString(toggler.firstElementChild.innerHTML); // based on the header being different
        toggler.scrollTo(0, 0); // helpful browser sometimes remember some weird scroll position that obscures the content
        function open(toggler) {
            const toggler_hash = PEATCMS.numericHashFromString(toggler.firstElementChild.innerHTML);
            toggler.classList.add('open');
            localStorage.setItem(toggler_hash, 'open');
        }

        function close(toggler) {
            const toggler_hash = PEATCMS.numericHashFromString(toggler.firstElementChild.innerHTML);
            toggler.classList.remove('open');
            localStorage.removeItem(toggler_hash)
        }

        function toggle(toggler) {
            if (toggler.classList.contains('open')) {
                close(toggler);
            } else {
                open(toggler);
            }
        }

        if (toggler.hasAttribute('data-instance_id') &&
            parseInt(toggler.getAttribute('data-instance_id')) !== self.instance.instance_id
        ) {
            if (toggler.hasAttribute('data-instance_id_message')) {
                toggler.innerHTML = toggler.getAttribute('data-instance_id_message');
            } else {
                toggler.innerHTML = 'Switch to native instance to manage this.';
            }
        } else {
            if (localStorage.getItem(toggler_hash.toString()) === 'open') open(toggler);
            el.onclick = function () { // remove any other onclick handlers that might linger on the button
                toggle(this.parentNode.parentNode);
            }
        }
    });
}

/**
 * Regular functions that startup the admin
 */
function peatcms_admin_start() {
    if (VERBOSE) console.log('Starting peatcms admin...');
    if (typeof NAV !== 'undefined') {
        peatcms_admin_setup();
    } else {
        setTimeout(peatcms_admin_start, 219);
    }
}

function peatcms_admin_setup() {
    let i, len;
    if (CMS_admin !== true) { // already setup, or being set up
        if (VERBOSE) console.log('... admin already started!');
        return;
    }
    // add admin flag to html
    PEAT.html_node.classList.add('the_admin_is_present');
    // finally startup
    window.CMS_admin = new PEATCMS_admin();
    if (VERBOSE) { // subscribe to all the events, and log them in the console
        for (i = 0, len = peatcms_events.length; i < len; ++i) {
            document.addEventListener(peatcms_events[i], PEATCMS_logevent);
        }
        console.log('... peatcms admin started.');
    }
}

/* function(s) */
function PEATCMS_logevent(evt) {
    if (evt.detail) {
        console.log(`PEATCMS emitted event: ${evt.type} with event.detail:`);
        console.log(evt.detail);
    } else {
        console.log(`PEATCMS emitted event: ${evt.type}`);
    }
}

/**
 * startup admin object
 */
if (document.readyState !== 'loading') {
    peatcms_admin_start();
} else {
    document.addEventListener('DOMContentLoaded', function () {
        peatcms_admin_start();
    });
}

/**
 * Set some things on the admin page, not dependent on actual admin
 */
document.addEventListener('peatcms.progressive_ready', function (e) {
    const slug = e.detail.slug;
    if ('admin_search' === slug) {
        // load the template_id options and on return update all the select lists for template_id
        if (document.querySelector('select[data-column_name="template_id"]')) {
            NAV.ajax('/__action__/admin_get_templates', {
                for: 'search',
                instance_id: NAV.instance_id
            }, function (json) {
                document.querySelectorAll('select[data-column_name="template_id"]').forEach(function (el) {
                    let i, option, temp, current_template_id = parseInt(el.getAttribute('data-value') || 0);
                    for (i in json) {
                        if (json.hasOwnProperty(i) && (temp = json[i]) && temp.hasOwnProperty('template_id')) {
                            option = document.createElement('option');
                            option.text = temp.name;
                            option.value = temp.template_id;
                            el.options[el.length] = option;
                            if (temp.template_id === current_template_id) el.selectedIndex = el.length - 1;
                        }
                    }
                    el.classList.remove('peatcms_loading');
                });
            });
        }
    } else if ('admin_get_templates' === slug) {
        [
            'select[data-column_name="template_id_order_confirmation"]',
            'select[data-column_name="template_id_payment_confirmation"]',
            'select[data-column_name="template_id_internal_confirmation"]'
        ].forEach(function (str) {
            if (document.querySelector(str)) {
                document.querySelectorAll(str).forEach(function (el) {
                    //console.log(el); // TODO this is looped through too often, you need to call the event on the element
                    let i, option, current_template_id = el.getAttribute('data-value') || '0';
                    if (!el.getAttribute('data-peatcms_ajaxified')) return;
                    for (i in el.options) {
                        if (!el.options.hasOwnProperty(i)) continue;
                        option = el.options[i];
                        //console.log(current_template_id + ' === ' + option.value + ' (' +  i + ')');
                        if (current_template_id === option.value) {
                            el.selectedIndex = i;
                            break;
                        }
                    }
                });
            }
        });
    }
});
