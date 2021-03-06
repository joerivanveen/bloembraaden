// NOTICE this javascript file must be included as the last file, because it generates events you might want to subscribe to
// TODO separate admin stuff from regular stuff more, to reduce the amount of javascript loaded for visitors
// TODO document_complete can sometimes not be called, it looks like the /__action__/javascript is not triggering load event (on menu page)
// TODO in template don't remove single spaces, see review / image_link class problem in feelactive.nl
if (VERBOSE) console.log('peat.js loaded');
// declare global objects
var PEAT, NAV;
// we have the following events:
var peatcms_events = [
    'peatcms.navigation_start',
    'peatcms.navigation_end',
    'peatcms.progressive_rendering',
    'peatcms.progressive_ready',
    'peatcms.document_rendering',
    'peatcms.document_ready',
    'peatcms.document_complete',
    'peatcms.message',
    'peatcms.form_posting',
    'peatcms.form_posted',
    'peatcms.form_failed',
    'peatcms.account_status_changed'
];

/**
 * after login or logout change account status in PEATCMS_globals
 * @since 0.7.9
 */
document.addEventListener('peatcms.form_posted', function (e) {
    var form, json, arr, i, len, is_account, user, action;
    if (e.detail && (form = e.detail.form) && form.hasAttribute('action')) {
        if ((form.action.indexOf && (action = form.action).indexOf('/__action__/account_') !== -1)
            || (form.action.value && (action = form.action.value).indexOf('/__action__/account_') !== -1)
        ) {
            // ‘/__action__/account_’ are all the account handling functions
            if ((json = e.detail.json) && json.hasOwnProperty('is_account')) {
                is_account = json.is_account;
                if (VERBOSE) console.log('Account status changed');
                window.PEATCMS_globals.is_account = is_account;
                // by default we also change the status for data-is_account elements, for more you should handle the event
                arr = document.querySelectorAll('[data-is_account]');
                for (i = 0, len = arr.length; i < len; ++i) {
                    arr[i].setAttribute('data-is_account', (is_account) ? 'true' : 'false');
                }
                // load email, phone and shipping and billing addresses into session
                if (true === is_account) {
                    user = json.__user__;
                    PEAT.setSessionVar('gender', user.gender);
                    PEAT.setSessionVar('email', user.email);
                    PEAT.setSessionVar('phone', user.phone);
                    // TODO mechanism for setting and getting actual billing and shipping addresses, by id in _user?
                    if (user.__addresses__.length > 0) {
                        PEAT.setSessionVar('shipping_address', user.__addresses__[0]);
                        PEAT.setSessionVar('billing_address', user.__addresses__[0]);
                    }
                } else {
                    PEAT.session = {}; // todo this is a terrible shortcut, we assume current user logged out
                }
                // produce an event with relevant details to catch
                form.dispatchEvent(new CustomEvent('peatcms.account_status_changed', {
                    bubbles: true,
                    detail: {
                        is_account: is_account,
                    }
                }));
            }
        }
    }
});

// TODO have a setting to choose between scroll down first then stick, or stick first then scroll down, or something
function PeatStickyColumns(leftColumn, rightColumn, spaceOnTop) {
    var self = this;
    if (!leftColumn || !rightColumn) return;
    // set style margin top to 0 now, so we don’t have to waste resources finding out if it has been set
    leftColumn.style.marginTop = '0px';
    rightColumn.style.marginTop = '0px';
    this.left = leftColumn;
    this.right = rightColumn;
    this.space = parseInt(spaceOnTop) || 0;

    window.addEventListener('scroll', self, false);
    window.addEventListener('resize', self, false);
    // cleanup or the scroll events will get stacked...
    PEAT.addEventListener('peatcms.navigation_start', function () {
        window.removeEventListener('scroll', self, false);
        window.removeEventListener('resize', self, false);
    }, true);
    self.handleEvent(); // initialize
}

PeatStickyColumns.prototype.handleEvent = function () {
    var left = this.left,
        right = this.right,
        left_rect = left.getBoundingClientRect(),
        right_rect = right.getBoundingClientRect(),
        difference = right_rect.height - left_rect.height;
    if (right_rect.left < left_rect.right) { // columns aren’t side by side
        this.doTheMargins(right, left, 0, 0);
        return;
    }
    if (difference > 0) {
        this.doTheMargins(right, left, difference, right_rect.top);
    } else {
        this.doTheMargins(left, right, -1 * difference, left_rect.top);
    }
}
PeatStickyColumns.prototype.doTheMargins = function (tall, short, difference, tall_top) {
    tall.style.marginTop = '0px';
    tall_top -= this.space;
    short.style.marginTop = Math.max(Math.min(-1 * tall_top, difference), 0) + 'px';
}


function Address_getAllUserAddresses() {
    var user;
    if ((user = PEATCMS_globals.__user__)) {
        if (user.hasOwnProperty('__addresses__')) return user.__addresses__;
    }
    return {};
}

function Address_getEmptyFields() {
    return {
        address_gender: '',
        address_name: '',
        address_company: '',
        address_postal_code: '',
        address_number: '',
        address_number_addition: '',
        address_street: '',
        address_street_addition: '',
        address_city: '',
        address_country: ''
    };
}

function Address(wrapper) {
    var i, len, inputs = wrapper.querySelectorAll('input');
    this.wrapper = wrapper;
    this.inputs = inputs;
    this.postcode_nl_fields = {};
    for (i = 0, len = inputs.length; i < len; ++i) {
        this.enhanceInput(inputs[i]);
    }
    this.enhanceLists();
    this.enhanceWrapper();
}

Address.prototype.enhanceWrapper = function () {
    var wrapper = this.wrapper, user_addresses, fields = this.getFields(), next, prev, reset;
    wrapper.setAttribute('data-paging', '0');
    // a wrapper that is not an individual user address can page through user addresses, if available
    // so the user can select a shipping_ and billing_ address easily
    // so, for it to work you need paging buttons, no address_id field, and at least one user address
    if (wrapper.querySelector('.paging') && fields.hasOwnProperty('address_id') === false) {
        if ((user_addresses = Address_getAllUserAddresses()).length > 0) {
            wrapper.setAttribute('data-paging', '1');
            wrapper.setAttribute('data-paging-index', '1');
            if ((next = wrapper.querySelector('.paging.next'))) {
                next.addEventListener('click', function () {
                    var index = 1 + parseInt(wrapper.getAttribute('data-paging-index')),
                        fields = Address_getEmptyFields(); // default to empty
                    if (index > user_addresses.length) {
                        index = 0; // the empty address
                    } else {
                        fields = user_addresses[index - 1]; // index = 0 based
                    }
                    wrapper.setAttribute('data-paging-index', index);
                    wrapper.Address.save(fields, true);
                    wrapper.Address.checkPostcodeFirst(null, fields);
                });
            }
            if ((prev = wrapper.querySelector('.paging.prev'))) {
                prev.addEventListener('click', function () {
                    var index = -1 + parseInt(wrapper.getAttribute('data-paging-index')),
                        fields = Address_getEmptyFields(); // default to empty
                    if (index !== 0) {
                        if (index < 0) index = user_addresses.length; // the empty address
                        fields = user_addresses[index - 1]; // index = 0 based
                    }
                    wrapper.setAttribute('data-paging-index', index);
                    wrapper.Address.save(fields, true);
                    wrapper.Address.checkPostcodeFirst(null, fields);
                });
            }
            if ((reset = wrapper.querySelector('.paging.reset'))) {
                reset.addEventListener('click', function () {
                    var index = 0, fields = Address_getEmptyFields();
                    wrapper.setAttribute('data-paging-index', index);
                    wrapper.Address.save(fields, true);
                    wrapper.Address.checkPostcodeFirst(null, fields);
                });
            }
        }
    }
}
Address.prototype.enhanceInput = function (input) {
    input.Address = this;
    // throttle type action, and save address on the type action as well as check postcode.nl validity etc.
    /*input.addEventListener('keyup', function () {
        var self = this; // the input element
        // throttle updating
        clearTimeout(self.timeout);
        self.timeout = setTimeout(function () {
            self.Address.send(self);
        }, 708);
    });*/
    // just do it on change for now, less stressful
    input.addEventListener('change', function () {
        this.Address.send(this);
    });
}
Address.prototype.enhanceCountryList = function () {
    var self = this, select_list;
    if ((select_list = this.wrapper.querySelector('select[data-field=country]'))) {
        select_list.addEventListener('change', function () {
            self.send(this);
        });
    }
}
Address.prototype.enhanceLists = function () {
    var self = this, select_lists, i, len;
    if ((select_lists = this.wrapper.querySelectorAll('select'))) {
        for (i = 0, len = select_lists.length; i < len; ++i) {
            select_lists[i].addEventListener('change', function () {
                self.send(this);
            });
        }
    }
}
Address.prototype.send = function (input) {
    var self = this;
    // send will save the address and also check postcode.nl if relevant
    self.checkPostcodeFirst(input, self.getFields(), function (input, fields) {
        self.save(fields);
    });
}
Address.prototype.save = function (fields, in_session_only) {
    var self = this, wrapper = self.wrapper;
    wrapper.setAttribute('data-updating', '1');
    if (fields.hasOwnProperty('address_id') && !in_session_only) {
        NAV.submitData('/__action__/update_address', fields, function (json) {
            self.updateClientOnly(json);
            wrapper.removeAttribute('data-updating');
        });
    } else {
        // update the address in session
        PEAT.setSessionVar(wrapper.id, fields, function (json) {
            // and the form
            self.updateClientOnly(json);
            wrapper.removeAttribute('data-updating');
        });
    }
}
Address.prototype.getFields = function () {
    var i, len, input, inputs = this.inputs, input_name, fields = {}, select_list, option;
    for (i = 0, len = inputs.length; i < len; ++i) {
        input = inputs[i];
        // NOTE the fields’ names must be neutral, so remove shipping or billing prefix from the input names in the form
        input_name = input.name.replace('shipping_', '').replace('billing_', '');
        fields[input_name] = input.value;
    }
    // get country fields
    if ((select_list = this.wrapper.querySelector('select[data-field="country"]'))
        && (option = select_list.options[select_list.selectedIndex])
    ) {
        if (option.hasAttribute('data-iso2')) fields['address_country_iso2'] = option.getAttribute('data-iso2');
        if (option.hasAttribute('data-iso3')) fields['address_country_iso3'] = option.getAttribute('data-iso3');
        fields['address_country_name'] = option.value;
    }
    // get gender field
    if ((select_list = this.wrapper.querySelector('select[data-field="gender"]'))
        && (option = select_list.options[select_list.selectedIndex])
    ) {
        fields['address_gender'] = option.value;
    }
    // return
    return fields;
}
Address.prototype.getUserAddress = function (fields) {
    var i, len, user = PEATCMS_globals.__user__, user_addresses, user_address = {}, address_id;
    if (null === fields) return {};
    if (fields.hasOwnProperty('address_id') && (address_id = fields.address_id)) {
        if (user.hasOwnProperty('__addresses__') && (user_addresses = user.__addresses__)) {
            for (i = 0, len = user_addresses.length; i < len; ++i) {
                if ((user_address = user_addresses[i]).hasOwnProperty('address_id')
                    && user_address['address_id'] === address_id
                ) {
                    return user_address;
                }
            }
        }
    }
    return {}; // not found
}
Address.prototype.updateClientOnly = function (fields) {
    var i, len, inputs = this.inputs, input, input_name, user_address = this.getUserAddress(fields);
    if (fields === null) return;
    // update the user address with all fields always
    for (input_name in fields) {
        if (fields.hasOwnProperty(input_name)) user_address[input_name] = fields[input_name];
    }
    // update the inputs in the form
    for (i = 0, len = inputs.length; i < len; ++i) {
        input = inputs[i];
        // NOTE the fields’ names must be neutral, so remove shipping or billing prefix from the input names in the form
        input_name = input.name.replace('shipping_', '').replace('billing_', '');
        if (fields.hasOwnProperty(input_name)) {
            if (document.activeElement !== input) input.value = fields[input_name];
        }
    }
    // update the country list in the form
    this.updateClientCountryList(fields);
    // update the gender list in the form
    this.updateClientGenderList(fields);
}
Address.prototype.updateClientGenderList = function (fields) {
    var select_list, options, value, i, len, wrapper = this.wrapper;
    if (!fields['address_gender']) return;
    value = fields['address_gender'];
    if ((select_list = wrapper.querySelector('select[data-field="gender"]'))) {
        for (i = 0, options = select_list.options, len = options.length; i < len; ++i) {
            if (i > 0 && options[i].value === value) {
                select_list.selectedIndex = i;
                return;
            }
        }
    }
}
Address.prototype.updateClientCountryList = function (fields) {
    var select_list, options, option, i, len, wrapper = this.wrapper;
    if ((select_list = wrapper.querySelector('select[name="country"]'))
        || (select_list = wrapper.querySelector('select[name="billing_country"]'))) {
        // the first option becomes the chosen one, with all 3 properties filled
        if (select_list.options[0].hasAttribute('data-chosen')) {
            option = select_list.options[0];
        } else {
            option = document.createElement('option');
            select_list.insertAdjacentElement('afterbegin', option);
        }
        option.setAttribute('data-iso2', fields['address_country_iso2']);
        option.setAttribute('data-iso3', fields['address_country_iso3']);
        option.value = fields['address_country_name'];
        option.innerText = fields['address_country_name'];
        select_list.selectedIndex = 0; // select the appropriate option
    } else { // this is a specific shipping country list where the values are id’s
        if ((select_list = wrapper.querySelector('select[name="shipping_country_id"]'))) {
            options = select_list.options;
            if (options.length < 3) { // wait for it to load
                wrapper.setAttribute('data-updating', '1');
                // TODO make renderProgressive event specific for elements that are being rendered, so you can subscribe better
                setTimeout(function (Address, fields) {
                    Address.updateClientCountryList(fields);
                }, 342, this, fields);
            } else {
                for (i = 0, len = options.length; i < len; ++i) {
                    if ((option = options[i]).hasAttribute('data-iso2')
                        && option.getAttribute('data-iso2') === fields['address_country_iso2']) {
                        if (select_list.selectedIndex !== i) {
                            select_list.selectedIndex = i;
                            select_list.dispatchEvent(new CustomEvent('change'));
                        }
                        break;
                    }
                }
                wrapper.removeAttribute('data-updating');
            }
        }
    }
}
Address.prototype.checkPostcodeFirst = function (input, fields, callback) {
    var addition, number, postal_code, prev = this.postcode_nl_fields, el, self = this;
    if (fields.hasOwnProperty('address_postal_code') && fields.hasOwnProperty('address_number')) {
        addition = fields.hasOwnProperty('address_number_addition') ? fields['address_number_addition'] : null;
        number = fields['address_number'];
        postal_code = fields['address_postal_code'];
        // if the following conditions are met just send it to the callback without checking postcode.nl
        if ( // check if the postal_code and number are halfway decent
            (false === PEATCMS.isInt(number) || PEATCMS.replace(' ', '', postal_code).length !== 6)
            // do not request if the info has not changed
            || JSON.stringify(prev) === JSON.stringify(fields)
            /*|| ((prev.hasOwnProperty('postal_code') && postal_code === prev.postal_code)
            && (prev.hasOwnProperty('number') && number === prev.number))*/
        ) {
            if (typeof callback === 'function') callback(input, fields);
            return;
        }
        // in the case of collect, also don’t bother
        if ((el = document.getElementById('shipping_address_collect')) && el.checked === true) {
            if ((el = document.getElementById('shipping_address_not_recognized'))) {
                el.innerHTML = '';
                el.style.opacity = 0;
            }
            if (typeof callback === 'function') callback(input, fields);
            return;
        }
        // cache the current values
        this.postcode_nl_fields = fields;
        // ok inquire chez postcode.nl
        this.timestamp = Date.now();
        NAV.ajax('/__action__/postcode', {
            postal_code: postal_code,
            number: number,
            number_addition: addition,
            timestamp: this.timestamp
        }, function (json) {
            var postcode_suggestion, el = document.getElementById('shipping_address_not_recognized') || null;
            //console.warn(self.timestamp +' === '+ json['timestamp']);
            if (self.timestamp !== json['timestamp']) return;
            if (VERBOSE) console.log(json);
            // if we receive a whole address, update the inputs
            if (json.success) {
                postcode_suggestion = json.response;
                // normalize to our own naming convention
                fields.address_street = postcode_suggestion.streetNen;
                fields.address_number = postcode_suggestion.houseNumber;
                fields.address_number_addition = postcode_suggestion.houseNumberAddition || addition || '';
                fields.address_postal_code = postcode_suggestion.postcode;
                fields.address_city = postcode_suggestion.cityShort;
                fields.address_country_iso2 = 'NL';
                fields.address_country_iso3 = 'NLD';
                fields.address_country_name = 'Nederland';
                if (el) {
                    el.innerHTML = '';
                    el.style.opacity = '0';
                }
            } else { // show unconfirmed message if applicable
                if (json.hasOwnProperty('error_message')) {
                    if ('Address not found' === json.error_message) {
                        if (el) {
                            el.innerHTML = el.getAttribute('data-message');
                            el.style.opacity = '1';
                        }
                    } else {
                        console.error('Postcode.nl error: ' + json.error_message);
                    }
                }
            }
            if (typeof callback === 'function') callback(input, fields);
        });
        return;
    }
    if (typeof callback === 'function') callback(input, fields);
}

/**
 * Translation function for javascript
 * @param str
 * @returns {*}
 * @since 0.6.14
 */
function __(str) {
    return str;
}

/**
 * Element
 */
function PEATCMS_element(slug, callback) {
    this.state = {};
    this.linkable_areas = []; // array of DOM elements holding linkable element types, per type
    this.config = {};

    return this.load(slug, callback);
}

PEATCMS_element.prototype.load = function (slug, callback) {
    var self = this;
    if (slug.charAt(0) !== '/') slug = '/' + slug;
    NAV.ajax(slug, false, function (data) {
        if (VERBOSE) console.log('Element is loading', data);
        if (data.hasOwnProperty('slug')) {
            // fill the object with this element
            self.state = data;
            data = null;
            self.ready = true;
            // cache me
            if (VERBOSE) console.log('Setting / refreshing cache for ' + self.state.slug);
            NAV.cache(self);
            // callback
            if (typeof callback === 'function') {
                callback(self);
            }
            return; // prevent failed callback from executing
        }
        if (typeof callback === 'function') {
            callback(false);
        }
    });
}

PEATCMS_element.prototype.isEditable = function () {
    var table_info = this.getTableInfo();
    if (!table_info || !table_info.hasOwnProperty('table_name')) return false;
    return (table_info.table_name.indexOf('cms_') === 0);
}

PEATCMS_element.prototype.render = function (callback, full_page) {
    if (!full_page) full_page = false;
    // render the element on the page
    PEAT.render(this, callback, full_page);
}
PEATCMS_element.prototype.edit = function (edit_area, callback) {
    var column_names = this.getColumnNames(),
        column_name, i, len, fields, el, element_name, config,
        linked_types = this.state['linked_types'],
        self = this;
    if (false === this.isEditable()) {
        console.error('‘' + this.state.slug.toString() + '’ is not editable');
        return;
    }
    // remember the edit area
    this.edit_area = edit_area;
    // if there lingers something, remove it
    // https://stackoverflow.com/questions/3955229/remove-all-child-elements-of-a-dom-node-in-javascript
    if (edit_area.childNodes.length > 0) {
        edit_area.innerHTML = '';
    }
    // set a reminder (for the 'new' button) which element is being edited
    el = document.createElement('button');
    el.setAttribute('data-element_type', this.state.type);
    el.classList.add('new', 'edit');
    el.innerText = '+';
    el.title = 'New ' + this.state.type;
    el.onclick = function () {
        CMS_admin.createElement(this.getAttribute('data-element_type'));
    };
    edit_area.insertAdjacentElement('afterbegin', el);
    // add an uncache link
    el = document.createElement('button');
    el.classList.add('edit');
    el.title = 'Warmup cache';
    el.onclick = function () {
        NAV.admin_uncache_slug();
    };
    el.innerText = '↫';
    edit_area.insertAdjacentElement('beforeend', el);
    // add a view link
    el = document.createElement('button');
    el.classList.add('edit');
    el.title = 'Show';
    el.onclick = function () {
        NAV.go(self.state.slug, true)
    };
    el.innerText = '👁';
    edit_area.insertAdjacentElement('beforeend', el);
    // default when editor config is missing...
    this.config = (typeof CMS_admin === 'object') ? CMS_admin.getEditorConfig(self.state.type) : {};
    if (!(config = this.config)) {
        config = {
            hidden_fields: {
                'instance_id': true,
                'taxonomy_id': true
            },
            field_order: []
        }
        for (i = 1, len = column_names.length; i < len; ++i) {
            config.field_order.push(column_names[i]);
        }
    }
    //console.warn(config);
    // add the columns, in the order of field_order, skipping the hidden (and absent) ones...
    for (i = 1, fields = config.field_order, len = fields.length; i < len; ++i) {
        column_name = fields[i];
        if (config.hidden_fields.hasOwnProperty(column_name)) continue;
        if (column_names.includes(column_name)) {
            try {
                edit_area.appendChild(new PEATCMS_actor(column_name, this).DOMElement);
            } catch (e) {
                console.error(e);
            }
        } else {
            // load possible children to attach
            for (element_name in linked_types) {
                if (linked_types.hasOwnProperty(element_name)) {
                    if (config.hidden_fields.hasOwnProperty(element_name)) continue;
                    if (['cross_parent', 'cross_child', 'properties'].includes(linked_types[element_name])) {
                        //console.log(element_name);
                        if (element_name === column_name) {
                            this.populateLinkableArea(element_name);
                        }
                    }
                }
            }
        }
    }
    // add a delete button
    el = document.createElement('button');
    el.innerText = 'DELETE';
    el.classList.add('delete');
    el.addEventListener('mouseup', function () {
        self.delete();
    });
    edit_area.insertAdjacentElement('beforeend', el);
    // add move to console link
    el = document.createElement('span');
    el.classList.add('button')
    el.addEventListener('mouseup', function () {
        CMS_admin.panels.open('console');
    });
    el.setAttribute('data-ctrl_key', 'Open console: CTRL+.');
    el.innerText = 'Open console →';
    edit_area.insertAdjacentElement('beforeend', el);
    // make links behave smoothly
    PEAT.ajaxifyDOMElements(edit_area);
    // this will open the edit area (when closed) and maybe do other tasks deemed necessary
    callback.call();
}

PEATCMS_element.prototype.delete = function () {
    var data = {'element_name': this.state.type, 'id': this.state[this.getTableInfo()['primary_key_column']]},
        self = this;
    if (confirm('Delete ' + this.state.title)) {
        NAV.ajax('/__action__/delete_element', data, function (json) {
            if (json.hasOwnProperty('success') && true === json.success) {
                self.edit_area.innerHTML = '';
                CMS_admin.panels.close();
                NAV.refresh();
            }
        });
    }
}

PEATCMS_element.prototype.hasLinked = function (type, id) {
    var linked = this.getLinked(type),
        has = false,
        element,
        element_id;
    for (element in linked) {
        if (false === PEATCMS.isInt(element)) continue;
        if (has === false && linked.hasOwnProperty(element))
            has = ((element_id = linked[element][type + '_id']) ? element_id === id : false);
    }
    return has;
}

PEATCMS_element.prototype.getLinked = function (type) { // will return empty array if no linked items are found
    var linked = this.state['__' + type + 's__'];
    return (typeof (linked) !== 'undefined') ? linked : [];
}

PEATCMS_element.prototype.setLinked = function (type, data) {
    // make sure setLinked always sets an array of appropriate elements / types
    var clean_data = [], // don't directly edit data, we might need it later (eg for message processing by PEATCMS_ajax)
        i, data_i, property;
    for (i in data) {
        if (data.hasOwnProperty(i)) {
            // check if this is actually of the right type, otherwise just don't set it
            if (data[i].hasOwnProperty(type + '_id')) {
                clean_data.push(data[i]);
                // also set single ones (e.g. image:slug image:description, etc)
                if (i === '0') { // for some reason the indexes are strings, oh well...
                    for (property in (data_i = data[i])) {
                        if (data_i.hasOwnProperty(property)) {
                            // don't do the children of the children etc.
                            if (property.indexOf(':') === -1 && property.indexOf('__') === -1)
                                this.state[type + ':' + property] = data_i[property];
                        }
                    }
                }
            }
        }
    }
    this.state['__' + type + 's__'] = clean_data;
    NAV.admin_uncache_slug(this.state.slug, true); // @since 0.10.4
    if (VERBOSE) {
        console.log('Data from setLinked:');
        console.log(clean_data);
    }
}
PEATCMS_element.prototype.populatePropertiesArea = function (type, suggestions, src) {
    var linkable_area = this.linkable_areas[type],
        linked_elements = this.getLinked(type),
        linked_element, i, len, children, suggestion, el,
        children_by_id = [], n, prop, props, x_value_id, h, btn, self = this;
    // properties can only be added (by propertyvalue) and must be explicitly deleted when no longer wanted
    // type = x_value...
    // build the properties interaction
    // first check that the local cache is cleared for refreshing everything
    NAV.invalidateCache();
    // we need to know all the properties as well...
    if (!this.hasOwnProperty('x_properties') && !this.hasOwnProperty('x_properties_loading')) {
        if (VERBOSE) console.log('Load properties');
        this.x_properties_loading = true;
        NAV.ajax('/__action__/admin_get_elements', {
            element: 'property'
        }, function (data) {
            if (data.hasOwnProperty('rows')) {
                self.x_properties = data.rows;
            }
            delete (self.x_properties_loading);
        });
    }
    if (typeof (linkable_area) === 'undefined') {
        linkable_area = document.createElement('div');
        linkable_area.classList.add('linkable', type);
        this.linkable_areas[type] = linkable_area;
        // add header + new button
        h = document.createElement('h3');
        h.innerText = 'Properties';
        h.className = 'divider';
        this.edit_area.insertAdjacentElement('beforeend', h);
        this.edit_area.insertAdjacentElement('beforeend', linkable_area);
    } else {
        for (i = 0, len = linkable_area.childNodes.length; i < len; ++i) {
            n = linkable_area.childNodes[i];
            children_by_id[n.getAttribute('data-x_value_id')] = n;
        }
    }
    // add the currently linked x_values (properties...)
    for (i in linked_elements) {
        if (false === linked_elements.hasOwnProperty(i)
            || false === PEATCMS.isInt(i)) continue;
        linked_element = linked_elements[i];
        x_value_id = linked_element.x_value_id;
        if (children_by_id[x_value_id]) {
            if (linkable_area.childNodes[i] !== children_by_id[x_value_id])
                linkable_area.insertBefore(children_by_id[x_value_id], linkable_area.childNodes[i]);
        } else {
            linkable_area.insertBefore(new PEATCMS_x_value(linked_element, this).DOMElement, linkable_area.childNodes[i]);
        }
    }
    ++i; // do not remove the actual last element... :-P
    // remove remaining / no longer valid
    while ((el = linkable_area.childNodes[i]) && !el.classList.contains('searchable')) {
        PEATCMS.removeNode(el);
    }
    if (!linkable_area.querySelector('.searchable')) {
        linkable_area.appendChild(new PEATCMS_searchable(type, this).DOMElement);
    }
    if (suggestions) {
        children = linkable_area.getElementsByClassName('suggestion');
        for (i = children.length - 1; i >= 0; --i) {
            PEATCMS.removeNode(children[i]);
        }
        for (i = 0, len = suggestions.length; i < len; ++i) {
            suggestion = suggestions[i];
            el = document.createElement('div');
            el.classList.add('suggestion');
            el.classList.add((suggestion.online) ? 'online' : 'offline');
            el.setAttribute('data-property_id', suggestion.property_id);
            el.setAttribute('data-property_value_id', suggestion.property_value_id);
            el.innerHTML = '<a href="/' + suggestion.slug + '">' + suggestion.title + '</a>';
            btn = document.createElement('button');
            btn.className = 'unlinked';
            btn.addEventListener('click', function () {
                var suggestion = this.parentElement;
                linkable_area.classList.add('peatcms_loading')
                NAV.ajax('/__action__/admin_x_value_link', {
                    element: self.getElementName(),
                    id: self.getElementId(),
                    property_id: parseInt(suggestion.getAttribute('data-property_id')),
                    property_value_id: parseInt(suggestion.getAttribute('data-property_value_id')),
                }, function (data) {
                    var el;
                    // you get the x_value links back, put them in this linkable area
                    self.setLinked('x_value', data);
                    self.populatePropertiesArea('x_value');
                    if ((el = linkable_area.querySelector('.searchable'))) {
                        el.select();
                    }
                });
            });
            el.insertAdjacentElement('afterbegin', btn);
            linkable_area.appendChild(el);
        }
        // make links behave smoothly
        try {
            PEAT.ajaxifyDOMElements(linkable_area);
        } catch (e) {
        }
    }
    // below the suggestions you can immediately create entries for property and property_value to speed up editing process
    if (src && PEATCMS.trim(src) !== '' && self.x_properties) {
        if (!(el = document.getElementById('property-create-new-inline'))) {
            el = document.createElement('div');
            el.id = 'property-create-new-inline';
            el.classList.add('create-new');
            el.innerHTML = '<h3>Create property value</h3>';
            linkable_area.insertAdjacentElement('beforeend', el);
        } else {
            linkable_area.appendChild(el); // move to the end (again)
        }
        el.querySelectorAll('div').forEach(function (el) {
            PEATCMS.removeNode(el);
        });
        var title = linkable_area.querySelector('.searchable').value;
        for (i = 0, props = self.x_properties, len = props.length; i < len; ++i) { // an array
            prop = props[i];
            n = document.createElement('div');
            n.innerHTML = prop.title + ':  ' + title;
            n.classList.add((prop.online) ? 'online' : 'offline');
            btn = document.createElement('span');
            btn.classList.add('button');
            btn.setAttribute('data-property_id', prop.property_id);
            btn.innerHTML = '+';
            btn.addEventListener('click', function () {
                NAV.ajax('/__action__/admin_x_value_create', {
                    element: self.getElementName(),
                    id: self.getElementId(),
                    property_id: parseInt(this.getAttribute('data-property_id')),
                    property_value_title: title
                }, function (data) {
                    var el;
                    self.setLinked('x_value', data);
                    self.populatePropertiesArea('x_value');
                    if ((el = linkable_area.querySelector('.searchable'))) {
                        el.select();
                    }
                });
            });
            n.insertAdjacentElement('afterbegin', btn)
            el.insertAdjacentElement('beforeend', n)
        }
    } else {
        if ((el = document.getElementById('property-create-new-inline'))) PEATCMS.removeNode(el);
    }
    linkable_area.classList.remove('peatcms_loading');
}
PEATCMS_element.prototype.populateLinkableArea = function (type, suggestions, src) {
    if (type === 'x_value') {
        this.populatePropertiesArea(type, suggestions, src);
        return;
    }
    NAV.invalidateCache();
    // TODO simplify this, I am sure this is too elaborate
    var linkable_area = this.linkable_areas[type],
        linked_elements = this.getLinked(type),
        linked_element, i, len, n, remove, slug,
        children_by_slug = [], h, btn;
    if (typeof (linkable_area) === 'undefined') {
        linkable_area = document.createElement('div');
        linkable_area.classList.add('linkable', type);
        this.linkable_areas[type] = linkable_area;
        // add header + new button
        h = document.createElement('h3');
        h.innerText = type;
        h.className = 'divider';
        btn = document.createElement('button');
        btn.innerText = '+';
        btn.setAttribute('data-element_name', type);
        btn.addEventListener('mouseup', function () {
            if (typeof CMS_admin === 'object')
                CMS_admin.createElement(this.getAttribute('data-element_name'));
        });
        h.insertAdjacentElement('beforeend', btn);
        this.edit_area.insertAdjacentElement('beforeend', h);
        this.edit_area.insertAdjacentElement('beforeend', linkable_area);
    } else {
        for (i = 0, len = linkable_area.childNodes.length; i < len; ++i) {
            n = linkable_area.childNodes[i];
            children_by_slug[n.getAttribute('data-peatcms_slug')] = n;
        }
    }
    for (i in linked_elements) {
        if (false === linked_elements.hasOwnProperty(i)
            || false === PEATCMS.isInt(i)) continue;
        linked_element = linked_elements[i];
        if (children_by_slug[linked_element.slug]) {
            if (linkable_area.childNodes[i] !== children_by_slug[linked_element.slug])
                linkable_area.insertBefore(children_by_slug[linked_element.slug], linkable_area.childNodes[i]);
        } else {
            linkable_area.insertBefore(new PEATCMS_linkable(type, linked_element, this).DOMElement, linkable_area.childNodes[i]);
        }
        linkable_area.childNodes[i].classList.add('linked');
    }
    // make dialog where user can find more elements to link
    if (!linkable_area.querySelector('.searchable')) {
        linkable_area.appendChild(new PEATCMS_searchable(type, this).DOMElement);
    }
    // remove the current suggestions
    remove = (Array.isArray(suggestions));
    if (remove === false) suggestions = [];
    for (i = linkable_area.childNodes.length - 1; i >= 0; --i) { // reverse to catch them all
        if (linkable_area.childNodes.hasOwnProperty(i)) {
            n = linkable_area.childNodes[i];
            if (n.hasOwnProperty('PEATCMS_linkable') && n.PEATCMS_linkable.isLinked() === false) {
                if (remove) {
                    linkable_area.removeChild(n);
                } else {
                    n.classList.remove('linked');
                    suggestions.unshift(n.PEATCMS_linkable.row);
                }
            }
        }
    }
    // add new suggestions
    for (i = 0; i < suggestions.length; ++i) {
        slug = suggestions[i].slug;
        if (children_by_slug[slug]) { // if it exists already as a DOMElement, manipulate it
            // if linked, keep it, if not, remove it and add new as a suggestion
            if (children_by_slug[slug].PEATCMS_linkable.isLinked()) {
                continue;
            } else {
                try {
                    linkable_area.removeChild(children_by_slug[slug]);
                } catch (e) {
                }
            }
        }
        //console.log(suggestions[i]);
        linkable_area.appendChild(new PEATCMS_linkable(type, suggestions[i], this).DOMElement);
    }
    // make links behave smoothly
    try {
        PEAT.ajaxifyDOMElements(linkable_area);
    } catch (e) {
    }
    linkable_area.classList.remove('peatcms_loading');
}

PEATCMS_element.prototype.chainParents = function (column_name, id) {
    console.log('chainParents called with column name ‘' + column_name + '’ and id: ' + id);
    var chain = { // you only need to know the parent, references are handled on the server
            // TODO make this a config somewhere so you have it in one place with the referencing tables
            // TODO cron job should handle unfinished business on the server
            'product_id': 'serie_id',
            'serie_id': 'brand_id',
            'brand_id': null,
        },
        parent_column, parent_id, actor_cached,
        self = this;
    if (chain.hasOwnProperty(column_name)) {
        actor_cached = this.getColumnByName(column_name).actor;
        actor_cached.DOMElement.classList.add('peatcms_loading');
        parent_column = chain[column_name];
        NAV.ajax('/__action__/update_element_and_parent_references', {
            // for the current element, update the whole chain
            'element': this.getElementName(),
            'id': this.getElementId(),
            'update_column_name': column_name,
            'update_column_value': id,
        }, function (data) {
            //console.log(data);
            actor_cached.prettyParent(data[column_name]);
            if (parent_column !== null && (parent_id = data[parent_column])) {
                self.chainParents(parent_column, parent_id)
            } else { // if we're done, refresh the page
                self.refreshOrGo(data.slug);
            }
        });
    } else {
        this.getColumnByName(column_name).actor.update(id, 'set');
    }
}

PEATCMS_element.prototype.set = function (data) {
    var column_name, column;
    for (column_name in data) {
        if (false === data.hasOwnProperty(column_name)) continue;
        // update the actor (will also update internal state)
        if ((column = this.getColumnByName(column_name)) && column.hasOwnProperty('actor')) {
            column.actor.set(data);
        }
    }
    // when the whole element is set, show the page
    this.refreshOrGo(data.slug);
}

PEATCMS_element.prototype.refreshOrGo = function (slug) {
    if (slug === NAV.getCurrentSlug()) {
        NAV.refresh(slug); // means el.render + replaceState in history :-)
    } /*else { // we don’t ‘go’ anymore, it would disrupt the flow
        NAV.go(slug);
    }*/
}

PEATCMS_element.prototype.getTableInfo = function () {
    return (typeof this.state.table_info === 'undefined') ? null : this.state.table_info;
}

PEATCMS_element.prototype.getColumns = function () {
    var table_info = this.getTableInfo();
    return table_info.hasOwnProperty('columns') ? table_info.columns : [];
}

PEATCMS_element.prototype.getColumnNames = function () {
    var table_info = this.getTableInfo();
    return table_info.hasOwnProperty('names') ? table_info.names : [];
}

PEATCMS_element.prototype.getColumnByName = function (column_name) {
    return this.getColumns()[column_name];
}

PEATCMS_element.prototype.getColumnValue = function (column_name) {
    return this.state[column_name];
}

PEATCMS_element.prototype.getElementName = function () {
    var table_info = this.getTableInfo();
    return table_info['table_name'].substr(4); // remove 'cms_' to get elementname from table
}

PEATCMS_element.prototype.getElementId = function () {
    var table_info = this.getTableInfo();
    return this.state[table_info['id_column']];
}

/**
 * ajax
 */
var PEATCMS_ajax = function () {
    window.peatcms_ajax_pending = []; // array keeps track of pending ajax calls
    this.slugs = {}; // @since 0.5.10 setup ‘out’ element cache to speed it up a bit more, TODO watch memory usage...
}

PEATCMS_ajax.prototype.ajaxHasPending = function () {
    return (window.peatcms_ajax_pending.length !== 0);
}

PEATCMS_ajax.prototype.ajaxAddPending = function (xhr) {
    window.peatcms_ajax_pending[window.peatcms_ajax_pending.length] = xhr;
}

PEATCMS_ajax.prototype.ajaxRemovePending = function (xhr) {
    window.peatcms_ajax_pending = window.peatcms_ajax_pending.filter(function (e) {
        return e !== xhr
    });
}
PEATCMS_ajax.prototype.ajaxShouldBlock = function () {
    var i, len, calls; // only block when update calls are being executed
    for (i = 0, calls = window.peatcms_ajax_pending, len = calls.length; i < len; ++i) {
        if (calls[i].responseURL.indexOf('/__action__/') > -1) return true;
    }
    return false;
}

/*
    PEATCMS_ajax.prototype.ajaxLogPending = function() {
        if (this.ajaxHasPending()) {
            console.log(window.peatcms_ajax_pending);
        } else {
            console.log('No pending ajax calls');
        }
    }
*/
PEATCMS_ajax.prototype.getHTTPObject = function () {
    if (typeof XMLHttpRequest != 'undefined') {
        return new XMLHttpRequest();
    }
    try {
        return new ActiveXObject("Msxml2.XMLHTTP");
    } catch (e) {
        try {
            return new ActiveXObject("Microsoft.XMLHTTP");
        } catch (e) {
        }
    }
    return false;
}

PEATCMS_ajax.prototype.feedbackUpload = function (event, progress_element) {
    var percent = event.loaded / event.total * 100;
    progress_element.style.marginTop = (100 - percent) + '%';
    //console.log('Upload progress: ' + percent + '%');
    // progress indicator is removed by subsequent reload TODO maybe not always (in the future)
    if (100 === percent) {
        progress_element.innerHTML = 'Scanning...'; // TODO integrate this with the sse_log...
    }
}

PEATCMS_ajax.prototype.fileUpload = function (callback, file, for_slug, element) {
    var xhr = this.getHTTPObject(), self = this, prgrs = document.createElement('div'), drop_area;
    // setup the element for progress feedback
    if (element !== null) {
        prgrs.className = 'progress';
        drop_area = element.querySelector('.drop_area') || element;
        drop_area.insertAdjacentElement('afterbegin', prgrs);
    } else {
        prgrs.className = 'PEATCMS progress'; // this will show at the side of the window
        document.body.insertAdjacentElement('afterbegin', prgrs);
    }
    xhr.upload.addEventListener('progress', function (event) {
        self.feedbackUpload(event, prgrs);
    }, false);
    // open after setting event listener (Firefox ignores the listener otherwise)
    xhr.open('POST', '/__action__/file_upload_admin/', true);
    this.setUpProcess(xhr, callback);
    xhr.setRequestHeader('Content-Type', 'application/octet-stream;'); // has no effect on the server
    xhr.setRequestHeader('X-File-Name', encodeURIComponent(file.name)); // maybe .fileName, for older Firefox browsers?
    xhr.setRequestHeader('X-Csrf-Token', PEAT.getSessionVar('csrf_token'));
    if (typeof for_slug === 'string') { // noinspection JSCheckFunctionSignatures // because it's a string here, phpStorm should shut up
        xhr.setRequestHeader('X-Slug', encodeURIComponent(for_slug));
    }
    if ('getAsBinary' in file && 'sendAsBinary' in xhr) {
        // Firefox 3.5
        xhr.sendAsBinary(file.getAsBinary());
    } else {
        xhr.send(file);
    }
}

PEATCMS_ajax.prototype.trackProgress = function (xhr, progress) {
    var loading_bar, state = xhr.readyState, i, len, calls, i_progress = 0, progress_width;
    if (!(loading_bar = document.getElementById('peatcms_loading_bar'))) return;
    if (!progress) progress = (state === 4) ? 1 : 0;
    // first 3 states account for 60% of the bar now, .2 of 1 each :-) last 40% is for the loading progress
    xhr.peatcms_progress = (state === 4) ? .6 + (.4 * progress) : .2 * state; // this updates the xhr in window.peatcms_ajax_pending since it's a reference
    // calculate the width of the bar based on all pending calls...
    for (i = 0, calls = window.peatcms_ajax_pending, len = calls.length; i < len; ++i) {
        i_progress += calls[i].peatcms_progress || 0;
    }
    //console.warn(i_progress.toString() + ' / ' + i.toString());
    progress_width = i_progress / i * 100;
    loading_bar.style.width = progress_width.toString() + 'vw';
    loading_bar.setAttribute('aria-valuenow', progress_width.toString());
    if (progress_width === 100) {
        window.setTimeout(function () {
            loading_bar.style.width = '0';
        }, 240)
    }
}

PEATCMS_ajax.prototype.setUpProcess = function (xhr, on_done, config) {
    var self = this,
        data = {}, i, len, arr, obj;
    xhr.withCredentials = true; // send the cookies to cross-sub domain (secure)
    if (typeof on_done === 'function') {
        // default was to track progress, so only if you receive a config with track_progress other than true, don’t
        if (!config || false === config.hasOwnProperty('track_progress') || true === config.track_progress) {
            // keep a list of outstanding ajax calls
            this.ajaxAddPending(xhr);
            // track the progress (per pending call...)
            this.trackProgress(xhr, .1); // start the bar immediately
            xhr.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    self.trackProgress(xhr, e.loaded / e.total);
                }
            }, false);
            xhr.peatcms_track_progress = true;
        } else {
            xhr.peatcms_track_progress = false;
        }
        xhr.onreadystatechange = function () {
            var slug, str;
            if (true === xhr.peatcms_track_progress) self.trackProgress(xhr);
            if (xhr.readyState === 4) {
                if (VERBOSE) if (xhr.status !== 200) console.warn('Received status: ' + xhr.status);
                try {
                    data = JSON.parse(xhr.responseText);
                    if (typeof data !== 'object') data = {};
                } catch (e) { // data is an empty object here
                    console.error(e);
                    console.log('Response was: ' + xhr.responseText);
                }
                if (true === xhr.peatcms_track_progress) self.ajaxRemovePending(xhr);
                if (xhr.status === 500) {
                    console.error(xhr.statusText);
                    PEAT.message(data.error, 'error');
                }
                // @since 0.8.16 permit simple ‘downloading’ (ie copying) of text content
                if (true === data.hasOwnProperty('download')) {
                    obj = data.download;
                    if (obj.hasOwnProperty('content')) {
                        str = data.download.content;
                        if (typeof (str) !== 'string') str = JSON.stringify(str);
                        if (PEAT.copyToClipboard(str)) {
                            str = (obj.hasOwnProperty('file_name')) ? obj.file_name : 'content';
                            PEAT.message(str + ' copied to clipboard!', 'note');
                        } else {
                            console.log(str);
                            PEAT.message('Could not be copied to clipboard. Data in console.', 'warn');
                        }
                    } else {
                        console.error('download property detected but without content...')
                    }
                }
                // @since 0.6.1 update session variables that were changed on the server
                if (true === data.hasOwnProperty('__session__')) {
                    obj = data['__session__'];
                    for (i in obj) {
                        if (obj.hasOwnProperty(i)) PEAT.updateSessionVarClientOnly(i, obj[i]);
                    }
                }
                // in case of a template object the returned messages and adminerrors are template parts
                if (false === data.hasOwnProperty('__html__')) {
                    // @since 0.7.9 when a __user__ object is sent, also update it in the globals! (not for templates)
                    if (true === data.hasOwnProperty('__user__')) {
                        window.PEATCMS_globals.__user__ = data.__user__;
                    }
                    // handle messages
                    if (true === data.hasOwnProperty('__messages__')) {
                        PEAT.messages(data['__messages__']); // using bracket notation for it can be an object or an array
                    }
                    if (true === data.hasOwnProperty('__adminerrors__')) {
                        for (i = 0, arr = data['__adminerrors__'], len = arr.length; i < len; ++i) {
                            console.error(arr[i]);
                        }
                    }
                }
                // @since 0.8.2 cache timestamp is verified, Bloembraaden may return ‘ok’ in stead of the whole object
                if (data.hasOwnProperty('x_cache_timestamp_ok') && data.hasOwnProperty('__ref')) {
                    if ((slug = '/' + decodeURIComponent(data.__ref))) {
                        if (true === self.slugs.hasOwnProperty(slug)) {
                            if (VERBOSE) console.log('Got ‘' + slug + '’ from cache');
                            //console.warn(self.slugs);
                            data = self.slugs[slug].el.state;
                        }
                    }
                }
                window.PEATCMS_globals.__guest__ = window.PEATCMS_globals.is_account ? {} : {show: true};
                if (data.hasOwnProperty('slugs')) data = unpack_temp(data);
                // do the callback
                on_done(data);
                // @since 0.6.11 redirect user when you receive a redirect_uri
                // @since 0.7.2 also external websites are allowed, placed redirect after the callback
                if (data.hasOwnProperty('redirect_uri')) {
                    NAV.go(data.redirect_uri, (data.redirect_uri.indexOf('http') !== 0));
                }
                // @since 0.8.16 re-render something
                if (data.hasOwnProperty('re_render')) {
                    PEAT.renderProgressive(data.re_render);
                }
            }
        }
    }
}

function unpack_temp(obj) {
    var i, unp, slugs = obj['slugs'] || [];
    // load the slugs
    if (!window.PEATCMS_globals.slugs) window.PEATCMS_globals.slugs = {};
    for (i in slugs) {
        if (slugs.hasOwnProperty(i)) {
            window.PEATCMS_globals.slugs[i] = slugs[i];
        }
    }
    delete obj['slugs'];
    unp = unpack_rec(obj, 0);
    for (i in unp) {
        if (unp.hasOwnProperty(i)) obj[i] = unp[i];
    }
    unp = null;
    return obj;
}

function unpack_rec(obj, nest_level) {
    var slugs = window.PEATCMS_globals.slugs, n, i, len, arr;
    if (nest_level > 2) return obj; // recursion stops here
    for (n in obj) {
        if (obj.hasOwnProperty(n)) {
            if (n === '__ref' && !obj.hasOwnProperty('slug')) { // check for slug to prevent bugs where __ref and slug are present
                return unpack_rec(slugs[obj[n]], nest_level); // __ref is considered to be this level
            }
            if (Array.isArray(obj[n])) {
                for (i = 0, arr = obj[n], len = arr.length; i < len; ++i) {
                    arr[i] = unpack_rec(arr[i], nest_level + 1);
                }
            } else {
                if (typeof obj[n] === 'object') obj[n] = unpack_rec(obj[n], nest_level + 1);
            }
        }
    }
    return obj;
}

PEATCMS_ajax.prototype.ajax = function (url, data, callback, method, headers) {
    var xhr = this.getHTTPObject(), slug;
    if (xhr === false) {
        console.error('Your browser does not support AJAX!');
        return;
    }
    method = (method && method.toUpperCase() === 'GET') ? 'GET' : 'POST'; // default to post
    if (!data) {
        data = {json: true}
    } else { // use array notation in stead of object, to suppress warning in IDE
        data['json'] = true;
        data['csrf_token'] = PEAT.getSessionVar('csrf_token');
    }
    // TODO get does not send the data, check if that is alright forever
    // if (method === 'GET') {
    //     url = url + '?' + JSON.stringify(data);
    // }
//    console.error(decodeURIComponent(url));
    // get from the server
    xhr.open(method, url, true);
    // @since 0.8.2 see if it’s in the cache
    if (this.slugs.hasOwnProperty((slug = decodeURIComponent(url)))) {
        xhr.setRequestHeader('X-Cache-Timestamp', this.slugs[slug].timestamp);
    }
    // set optional headers @since 0.6.0
    if (headers) {
        if (VERBOSE) console.warn('Setting xhr headers');
        var header;
        for (header in headers) {
            if (headers.hasOwnProperty(header)) {
                if (VERBOSE) console.log(header + ': ' + headers[header])
                xhr.setRequestHeader(header, headers[header]);
            }
        }
    }
    this.setUpProcess(xhr, callback, data['peatcms_ajax_config']);
    if (method === 'POST') {
        //xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
        if (VERBOSE) console.log('sending', data);
        xhr.send(JSON.stringify(data));
    } else {
        xhr.send(null);
    }
}
PEATCMS_ajax.prototype.cache = function (el) {
    var path;
    if (el.hasOwnProperty('state') && (path = el.state.path)) {
        if (path.indexOf('__') !== 0) { // cache the element (‘__’ elements are dynamic and should not be cached)
            if (path.charAt(0) !== '/') path = '/' + path; // slugs are cached with leading / (forward slash) (because of url in ajax also has it)
            this.slugs[path] = {el: el, timestamp: el.state.x_cache_timestamp || Date.now()};
            if (VERBOSE) console.log('...cached as ‘' + path + '’');
        }
    }
}
PEATCMS_ajax.prototype.invalidateCache = function (slug) {
    if (!slug) {
        this.slugs = {};
    } else if (typeof slug === 'string') {
        delete (this.slugs[slug]);
    }
}

var PEATCMS_template = function (obj) {
    this.template = obj;
    this.progressive_tags = {};
    this.doublechecking = []; // this variable is used to prevent infinite loops when the template encounters incorrect complex tags
    if (!window.cached_nodes) window.cached_nodes = {}; // TODO is that really the sane choice, window.cached_nodes?
}
PEATCMS_template.prototype.renderProgressive = function (tag, slug) {
    // get the remaining tags to render them progressively
    var tags = this.progressive_tags,
        self = this,
        t, i, len, progressor, el, elements;
    if (!slug) slug = tag; // default to the same, @since 0.5.15 you can specify a different slug to render in the tag
    // since 0.5.2 option to render the parts specific to one slug only, e.g. for updating of shoppinglist
    // TODO since this can only be called after all the tags have been rendered once, make a mechanism so it doesn't fail
    if (tag) {
        if (tags.hasOwnProperty(tag)) {
            self.renderProgressiveLoad(slug, tag);
        } else {
            if (VERBOSE) console.warn('No template for ' + tag);
        }
        return;
    } // else, render all tags remaining (since 0.4.0)
    for (t in tags) {
        if (false === tags.hasOwnProperty(t)) continue;
        elements = []; // collect elements for this tag, if any
        for (i = 0, len = tags[t].length; i < len; ++i) {
            if ((progressor = tags[t][i]) && (el = document.getElementById(progressor['dom_element_id']))) {
                // collect the elements you need to render
                // TODO evaluate the conditions here, for now simply set to true by the convertTagsRemaining method
                if (progressor['condition'] === true) {
                    elements[elements.length] = {'index': i, 'element': el};
                    el.classList.add('peatcms_loading');
                }
            }
        }
        if (elements.length > 0) {
            this.progressive_tags[t] = elements; // remember this for ajax
            PEAT.loderunner++; // TODO bleeding over to the instantiated object :-(
            // load the templates each time, but if they're already rendered, don't bother getting the objects again
            // TODO make react-like thingie that checks if all the nodes are properly rendered,
            // TODO if not get the object from cache, no need to trip to the server all the time
            if (VERBOSE) console.log('Progressive loading of ' + t);
            // NOTE be careful, currently the loading of templates depends on this
            self.renderProgressiveLoad(t, t);
        }
    }
}
PEATCMS_template.prototype.renderProgressiveLoad = function (slug, tag) {
    var el, self = this, data = {render_in_tag: tag}; // Send info to the server to let it know where the request comes from
    if (null === (el = NAV.getCurrentElement())) {
        console.warn('Couldn’t get element to send as originator during template.renderProgressive');
    } else {
        data.type = el.state.type;
        data.id = el.state[el.state.type + '_id'];
    }
    if ((tag = NAV.tagsCache(slug))) {
        self.renderProgressiveTag(tag);
    } else {
        NAV.ajax('/' + slug, data, function (json) {
            self.renderProgressiveTag(json);
        });
    }
}
PEATCMS_template.prototype.renderProgressiveTag = function (json) {
    var i, len, tags, tag, render_in_tag, html, el, slug,
        node, node_name, nodes, nodes_i, nodes_index, nodes_len, parent_node,
        cache_name, cached_nodes, cache_text;
    json = unpack_temp(json);
    if (json.hasOwnProperty('slug')) {
        slug = json.slug;
        // @since 0.5.10 you can render in a different tag than the official slug
        render_in_tag = json.render_in_tag || slug;
        NAV.tagsCache(render_in_tag, json);
        if ((tags = this.progressive_tags[render_in_tag])) {
            for (i = 0, len = tags.length; i < len; ++i) {
                tag = tags[i];
                if (!(el = tag.element)) continue; // this is the option tag in the DOM holding the place for this partial template
                if (!(parent_node = el.parentNode)) continue; // @since 0.7.7 check and cache the parent node
                el.classList.remove('peatcms_loading');
                if (VERBOSE) {
                    console.log('Rendering ' + slug + ' in ' + render_in_tag);
                    console.log(json);
                }
                html = this.removeSingleTagsRemaining(this.renderOutput(json, this.template[render_in_tag][tag.index]));
                cache_name = slug + '_' + tag.index;
                try {
                    nodes = new DOMParser().parseFromString(html, 'text/html').body.childNodes;
                    cached_nodes = [];
                    nodes_index = 0;
                    for (nodes_i = 0, nodes_len = nodes.length; nodes_i < nodes_len; ++nodes_i) {
                        node = nodes[nodes_index]; // keep manipulating the first one, because they're automatically removed
                        if ((node_name = node.nodeName) !== '#comment') {
                            // @since 0.5.10 convert text nodes to spans, so they can be manipulated more easily
                            if (node_name === '#text') {
                                if (node.textContent.trim() === '') {
                                    ++nodes_index; // skip empty text, start manipulating the next node
                                    continue;
                                } else {
                                    cache_text = node.textContent;
                                    node = document.createElement('span');
                                    node.innerText = cache_text;
                                }
                            }
                            el.insertAdjacentElement('beforebegin', node);
                            cached_nodes.push(node);
                        } else {
                            ++nodes_index; // skip this one, start manipulating the next one
                        }
                    }
                    // @since 0.7.7 only remove old nodes if still relevant
                    if ((nodes = window.cached_nodes[cache_name]) && el) {
                        //if (VERBOSE) console.log('Removing previously rendered nodes:');
                        for (nodes_i = 0, nodes_len = nodes.length; nodes_i < nodes_len; ++nodes_i) {
                            PEATCMS.removeNode(nodes[nodes_i]);
                        }
                    }
                    window.cached_nodes[cache_name] = cached_nodes; // the new nodes are cached from now on
                    PEAT.ajaxifyDOMElements(parent_node);
                    PEAT.currentSlugs(parent_node);
                    // @since 0.5.15 produce an event with relevant details to catch
                    el.dispatchEvent(new CustomEvent('peatcms.progressive_ready', {
                        bubbles: true,
                        detail: {
                            placeholder_element: el, // todo DEPRECATED!
                            parent_element: parent_node,
                            slug: slug
                        }
                    }));
                    // @since 0.7.2: for non-dynamic tags, load only once, remove placeholder so it is skipped from now on
                    // TODO make it more logical which ones can stay and which ones can’t
                    //console.error(render_in_tag + ' will remove node? ' + (render_in_tag.indexOf('__') !== 0));
                    if (render_in_tag.indexOf('__') !== 0) PEATCMS.removeNode(el);
                    if (render_in_tag.indexOf('/instagram/feed/') !== -1) PEATCMS.removeNode(el);
                } catch (e) {
                    console.error(e);
                }
            }
            PEAT.registerAssetLoad(render_in_tag);
        } else {
            console.error('Returned slug ‘' + slug + '’ not found in progressive tags.');
        }
    } else {
        console.error('No slug found in json returned for progressive loading.');
    }
}

PEATCMS_template.prototype.render = function (out) {
    // master template contains page html broken down in parts
    //console.log(this.template);
    // benchmarking (was: ~380 ms 2020-02-10 with petitclos template)
    // benchmarking (was: ~18000 ms 2022-07-14 with petitclos template)
    /*console.warn('currently benchmarking with 1000 renders...');
    var hallo = new Date(),
        i;
    for (i = 0; i < 1000; ++i) {
        this.renderOutput(out, this.template);
    }
    console.log('it took (ms): ' + (new Date() - hallo));*/
    return this.convertTagsRemaining(this.renderOutput(out, this.template));
}
// check if the string needle is currently in an open html tag in string haystack
PEATCMS_template.prototype.inOpenTag = function (needle, haystack) {
    var pos = haystack.indexOf(needle) + needle.length;
    if (haystack.indexOf('<', pos) < haystack.indexOf('>', pos))
        return false;
    return true; // err on the side of safety
}

PEATCMS_template.prototype.renderOutput = function (out, template) {
    var break_reference = template.__html__ || template.html || '', // todo remove second one when all templates are published
        tag_name, output_object, processed_object, type_of_object, value,
        admin = (typeof CMS_admin === 'object'),
        html = break_reference,
        // vars needed later:
        len, i, temp_i, row_i, temp_remember_html, sub_template, row_template, sub_html, build_rows, obj, obj_id,
        __count__, add_string, in_open_tag, check_if = {},
        // vars used for | method_name calling
        start, end, function_name,
        // vars used by : template show / hide
        content, parts, equals, is_false, str_to_replace;
    // process out object
    if (out.hasOwnProperty('__ref')) {
        out = unpack_temp(out, 1);
    }
    for (tag_name in out) {
        if (false === out.hasOwnProperty(tag_name)) continue;
        if ('undefined' === (type_of_object = typeof (output_object = out[tag_name]))) continue;
        if ('object' === type_of_object) {
            // this is a complex element which might contain indexed values that are rows
            if (template.hasOwnProperty(tag_name)) {
                // for each occurrence in the template, render this out object please
                //console.log('going to renderOutput this ' + tag_name);
                //console.log(template[tag_name]);
                for (i = 0, len = template[tag_name].length; i < len; i++) {
                    sub_template = PEATCMS.cloneShallow(template[tag_name][i]);
                    break_reference = sub_template.__html__ || sub_template.html || ''; // todo remove second one when templates are published
                    temp_remember_html = break_reference;
                    in_open_tag = this.inOpenTag(tag_name + '[' + i + ']', html);
                    if (output_object.hasOwnProperty(0)) { // build rows if present
                        //console.log('output with rows: ', output_object);
                        __count__ = output_object.length;
                        output_object.__count__ = __count__;
                        // prepare template for rows
                        sub_template = this.convertTemplateToRow(sub_template);
                        for (temp_i = 0; temp_i < sub_template.__row__.length; temp_i++) {
                            row_template = PEATCMS.cloneShallow(sub_template.__row__[temp_i]);
                            build_rows = '';
                            for (row_i in output_object) {
                                if (false === output_object.hasOwnProperty(row_i)) continue;
                                if (false === PEATCMS.isInt(row_i)) continue
                                // this is a row
                                value = output_object[row_i];
                                if (typeof value === 'string') {
                                    obj = {value: value};
                                } else {
                                    // @since 0.7.6 do not render items that are not online
                                    if (false === admin && value.hasOwnProperty('online') && false === value.online) continue;
                                    obj = value;
                                }
                                obj.__count__ = __count__;
                                obj.__index__ = row_i;
                                if (admin && false === in_open_tag) {
                                    if (obj.hasOwnProperty('id')) {
                                        build_rows += '<span class="PEATCMS_data_stasher" data-peatcms_id="' + obj.id +
                                            '" data-table_name="' + obj.table_name + '" data-tag="' + tag_name + '"></span>';
                                    }
                                    if (obj.slug && obj.type) {
                                        obj_id = obj.type + '_' + obj[obj.type + '_id'];
                                        build_rows += '<span class="PEATCMS_edit_button" data-peatcms_slug="' + obj.slug +
                                            '" data-peatcms_id="' + obj_id + '[' + i + ']"></span>'; // placeholder
                                    }
                                }
                                // if this row doesn't contain any tags that are different for each row,
                                // just leave it at the first execution, repetition is unnecessary
                                if (parseInt(row_i) === 1) { // check this only the second time the row is processed
                                    if ((add_string = this.renderOutput(obj, row_template)) === build_rows) {
                                        // leave it as is and stop processing rows
                                        sub_template.__html__ = build_rows;
                                        break; // don't process any more rows from this output_object
                                    }
                                }
                                build_rows += this.renderOutput(obj, row_template);
                            }
                            sub_template.__html__ = sub_template.__html__.replace('{{__row__[' + temp_i + ']}}', build_rows);
                        }
                    }
                    sub_html = this.renderOutput(output_object, sub_template);
                    // remove entirely if no content was added
                    if (sub_html === temp_remember_html) {
                        sub_html = '';
                    }
                    html = html.replace('{{' + tag_name + '[' + i + ']}}', sub_html);
                }
            }
        } else if (['string', 'number', 'boolean'].includes(type_of_object)) {
            check_if[tag_name] = output_object; // @since 0.10.7 remember tags to check for if-statements in template last
            html = PEATCMS.replace('{{' + tag_name + '}}', output_object.toString(), html);
            // @since 0.4.6: simple tags can also be processed by a function by using a pipe character |, {{tag|function_name}}
            while ((start = html.indexOf('{{' + tag_name + '|')) > -1) {
                start += 3 + tag_name.length;
                end = html.indexOf('}}', start);
                function_name = html.substring(start, end);
                if (typeof this['peat_' + function_name] === 'function') {
                    processed_object = this['peat_' + function_name](output_object);
                } else if (typeof window[function_name] === 'function') { /* @since 0.5.9 allow user functions */
                    processed_object = window[function_name](output_object).toString();
                } else {
                    console.warn('PEATCMS template function "' + function_name + '" not found');
                    processed_object = output_object.toString() + ' <span class="warn">(template function not found: ' + function_name + ')</span>';
                }
                html = PEATCMS.replace('{{' + tag_name + '|' + function_name + '}}', processed_object, html);
            }
        } else {
            if (VERBOSE) console.warn('Unrecognized type of tag for ' + tag_name, type_of_object);
        }
    }
    for (tag_name in check_if) {
        if (false === check_if.hasOwnProperty(tag_name)) continue;
        output_object = check_if[tag_name];
        // @since 0.4.12: simple elements can show / hide parts of the template
        while ((start = html.indexOf('{{' + tag_name + ':')) !== -1) {
            // @since 0.7.9 you can use ‘equal to’ operator ‘==’
            equals = null;
            if (html.indexOf('{{' + tag_name + ':==') !== -1) {
                start += 5 + tag_name.length;
                equals = html.substring(start, html.indexOf(':', start)).toLowerCase();
                is_false = (false === out.hasOwnProperty(tag_name) || String(out[tag_name]).toLowerCase() !== equals);
                start += equals.length + 1;
            } else {
                start += 3 + tag_name.length;
                is_false = !output_object;
            }
            end = html.indexOf('}}', start);
            if (-1 === end) {
                html = PEATCMS.replace('{{' + tag_name + ':', '<span class="warn">If-error near ' + tag_name + '</span>', html);
                continue;
            }
            content = html.substring(start, end);
            parts = content.split(':not:'); // the content can be divided in true and false part using :not:
            str_to_replace = '{{' + tag_name + ((equals) ? ':==' + equals + ':' : ':') + content + '}}';
            if (is_false) {
                if (parts.length > 1) { // display the 'false' part
                    html = PEATCMS.replace(str_to_replace, parts[1], html);
                } else { // forget it
                    html = PEATCMS.replace(str_to_replace, '', html);
                }
            } else { // display the 'true' part, substitute original value into ::value::
                html = PEATCMS.replace(str_to_replace, PEATCMS.replace('::value::', output_object, parts[0]), html);
            }
        }
    }
    // return this.convertTagsRemaining(html);
    return html; // removesingletagsremaining prevents similar single tags being rendered in an outer loop
    // e.g. the image:slug of a variant in a product excerpt, but also csrf_token in a form inside something else...
}

/**
 * This method takes all indexed tags in a html string and stores them in this.progressive_tags and
 * replaces the tag itself with an <option> tag with a specific id that for lazy (progressive) loading
 * by method renderProgressive() that also depends on the this.progressive_tags object
 *
 * Also removes any remaining single tags so the returned html should be clean
 *
 * @param string the html to treat
 * @returns string cleaned html with <script> tags for the progressive tags
 */
PEATCMS_template.prototype.convertTagsRemaining = function (string) {
    // you only have indexed complex tags left that are for progressive loading, and possibly single tags that are not filled
    // convert indexed tags to a DOMElement that can be accessed later, and cache them in the progressive object
    var html = string.toString(), // break_reference
        template = this.template,
        t, index, src, src_id, tags = {};
    for (t in template) {
        if (template.hasOwnProperty(t) && html.indexOf('{{' + t) > -1) {
            tags[t] = [];
            for (index = 0; index < 100; ++index) {
                src = '{{' + t + '[' + index + ']}}';
                if (html.indexOf(src) === -1) break;
                // TODO get the condition(s) for loading the tag here (for now simply true)
                src_id = t + '_' + index;
                tags[t][index] = {'condition': true, 'dom_element_id': src_id};
                html = html.replace(src, '<option id="' + src_id + '" data-peatcms-placeholder="1"></option>');
            }
        }
    }
    this.progressive_tags = tags;
    tags = null;
    // return the updated html
    return this.removeSingleTagsRemaining(html);
}

PEATCMS_template.prototype.removeSingleTagsRemaining = function (string) {
    var html = string.toString(), // break_reference
        start, next_start, end;
    // remove single tags
    while ((start = html.indexOf('{{')) !== -1) {
        end = html.indexOf('}}', start);
        next_start = html.indexOf('{{', start + 2);
        while (next_start < end && -1 !== next_start) {
            next_start = html.indexOf('{{', end);
            end = html.indexOf('}}', end + 2);
        }
        if (-1 === end) {
            console.error('tag_error: ' + html.substr(start, 20) + '...');
            html = html.replace('{{', ' <span class="error">tag_error</span> ');
        }
        html = PEATCMS.replace(html.substring(start, end + 2), '', html);
    }
    return html;
}

PEATCMS_template.prototype.getComplexTagString = function (tag_name, html, offset) {
    // always grab them with an EVEN number of complex tags between them
    var search = '{%' + tag_name + '%}',
        start = html.indexOf(search),
        end, string;
    if (!offset) offset = 0; // replacing default value which is not supported < ES6
    if (offset <= start) offset = start + 1; // changed < to <= following server side bugfix
    if (offset < html.length) {
        end = html.indexOf(search, offset);
        if (end > -1) {
            end += search.length;
            string = html.substring(start, end); // this string includes the outer complex tags
            if (this.hasCorrectlyNestedComplexTags(string)) {
                this.doublechecking = [];
                return string;
            } else {
                if (this.doublechecking[string]) {
                    PEAT.message('Error in ->getComplexTagString for: ' + string, 'error');
                    return PEATCMS.replace(search, '', string);
                } else {
                    this.doublechecking[string] = true;
                }
                // these are nested tags, so skip the next same one as well, to speed things up
                offset = html.indexOf(search, end + 1) + 1;

                return this.getComplexTagString(tag_name, html, offset);
            }
        }
    }

    return false;
}

PEATCMS_template.prototype.findComplexTagName = function (string) {
    var html = string.toString(); // break_reference
    var search = '{%',
        start = html.indexOf(search);
    if (start > -1) {
        // grab the tagName:
        start += 2;

        return html.substring(start, html.indexOf('%}', start));
    } else {
        return false; // ends while loop in prepare();
    }
}

PEATCMS_template.prototype.hasCorrectlyNestedComplexTags = function (html) { // used to be called hasEvenNumberOfComplexTags
    // all the tags need to form a 'pyramid', always be in pairs, from outside to in, if not they are incorrectly nested
    var string = html.toString(), // break_reference
        tag_name;
    if ((tag_name = this.findComplexTagName(string))) {
        var search = '{%' + tag_name + '%}',
            len = search.length,
            pos;
        // remove the outer two occurrences and check if the inner part still ->hasCorrectlyNestedComplexTags
        if (-1 !== (pos = string.indexOf(search))) {
            string = string.substr(pos + len);
            if (-1 !== (pos = string.lastIndexOf(search))) {
                string = string.substr(0, pos);

                return this.hasCorrectlyNestedComplexTags(string);
            } else { // there was only one of the tags, that is an error
                return false;
            }
        }
    }
    // if there are 0 complex tags left, the pyramid has reached its summit correctly
    return true;

}
PEATCMS_template.prototype.convertTemplateToRow = function (template) {
    var break_reference = template.__html__ || template.html || '', // todo remove second one after all templates are published
        html = break_reference;
    // convert the whole line to a row, this will be undone during processing
    // when it turns out this template does not contain tags for the row
    if (!template.hasOwnProperty('__row__')) {
        break_reference = PEATCMS.cloneShallow(template);
        template.__row__ = [break_reference]; // [] = convert to indexed array
        html = '{{__row__[0]}}';
    }
    template.__html__ = html;

    return template;
}

PEATCMS_template.prototype.getInnerHtml = function (node_name, html) {
    // get a certain nodes inner html from the template html, TODO it only returns the first occurrence, maybe return an array?
    var start = html.indexOf('<' + node_name),
        length = html.indexOf('>', start) + 1,
        end = html.indexOf('</' + node_name + '>', start);
    if (start > -1) {
        return html.substring(length, end);
    }
    return null;
}

PEATCMS_template.prototype.getInnerContent = function (tagString) // this assumes the string is correct, there are no checks
{
    var start = tagString.indexOf('%}') + 2, // start at the end of the opening tag
        end = tagString.lastIndexOf('{%'); // end at the beginning of the end tag

    return tagString.substring(start, end);
}

PEATCMS_template.prototype.getAttributes = function (node_name, html) {
    var start = html.indexOf('<' + node_name),
        end = html.indexOf('>', start + 1) + 1,
        str = html.substring(start, end) + '</' + node_name + '>';
    // create empty node from this string and get its attributes using DOMParser
    try {
        return new DOMParser().parseFromString(str, 'text/xml').childNodes[0].attributes;
    } catch (e) {
        console.error('Parsing attributes failed');
        console.error(e);
        return false;
    }
}
// for now this only returns the childNodes of the first occurrence of the node_name in the supplied html
PEATCMS_template.prototype.getChildNodes = function (node_name, html) {
    // create a dom object from the html, createContextualFragment not supported by older browsers
    return this.getChildNodesRecursive(node_name.toUpperCase(), new DOMParser().parseFromString(html, 'text/html'));
}
PEATCMS_template.prototype.getChildNodesRecursive = function (node_name, dom_obj) {
    var i, len, node, nodes, child_nodes = null;
    if ((nodes = dom_obj.childNodes)) {
        // walk the nodes until you reach the asked one, and return its children
        for (i = 0, len = nodes.length; i < len; ++i) {
            node = nodes[i];
            if (node.nodeName === node_name) { // nodeName in html document always returns uppercase
                return node.childNodes;
            }
        }
        // look one level deeper
        for (i = 0, len = nodes.length; i < len; ++i) {
            node = nodes[i];
            if ((child_nodes = this.getChildNodesRecursive(node_name, node))) return child_nodes;
        }
    }
    return null;
}

/**
 * SECTION with functions (filters) that can be called in the template within a simple tag
 * using a pipe character | (eg: {{title|clean}}
 * Ideally these should exactly mirror the ones on the server...
 */

/**
 * Cleans a string from tags and enters
 * @param str string the 'dirty' string
 * @returns string the string stripped of tags and enters
 */
PEATCMS_template.prototype.peat_clean = function (str) {
    str = str.toString().replace(/<\/?[^>]+>/gi, ' ');
    return PEATCMS.replace('"', '&quot;', PEATCMS.replace('\n', ' ', str));
}

PEATCMS_template.prototype.peat_format_order_number = function (str) {
    return str.match(/.{1,4}/g).join(' ');
}

PEATCMS_template.prototype.peat_plus_two = function (str) {
    var num;
    if ((num = parseInt(str)) == str) {
        return num + 2;
    }
    return str;
}

PEATCMS_template.prototype.peat_plus_one = function (str) {
    var num;
    if ((num = parseInt(str)) == str) {
        return num + 1;
    }
    return str;
}

PEATCMS_template.prototype.peat_enquote = function (str) {
    return PEATCMS.replace('"', '&quot;', str);
}

PEATCMS_template.prototype.peat_no_render = function (str) {
    return PEATCMS.replace('{', '&#123;', str);
}

PEATCMS_template.prototype.peat_encode_for_template = function (str) {
    var p = document.createElement("p");
    p.textContent = str;
    return this.peat_no_render(p.innerHTML);
}
PEATCMS_template.prototype.peat_as_float = function (str) {
    var flt;
    str = PEATCMS.replace(PEATCMS_globals.radix, '', str);
    str = PEATCMS.replace(' ', '', str);
    str = PEATCMS.replace(PEATCMS_globals.decimal_separator, '.', str);
    // convert to float
    if ((flt = parseFloat(str))) {
        return flt;
    }
    console.error('as_float templating function failed on ' + str);
    //bugfix 0.7.8 continue with empty string if it didn’t work
    return '';
}
PEATCMS_template.prototype.peat_format_money = function (str) { // TODO use the instances radix and decimal settings
    if (false === PEATCMS.isInt(str)) return '0';
    var n = parseInt(str) / 100,
        c = 2,
        d = ',',
        t = '.', // was: e
        s = n < 0 ? "-" : "",
        i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + "",
        j = (i.length) > 3 ? i.length % 3 : 0;
    return s + (j ? i.substr(0, j) + t : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : "");
}

/**
 * site object is called PEATCMS, instantiated later as global PEAT
 */
var PEATCMS = function () {
    let session, el, data_set, attr, style, self = this;
    this.eventListeners = {};
    this.status_codes = {
        'loading': 1,
        'rendering': 2,
        'ready': 3,
        'complete': 4
    };
    this.document_status = this.status_codes.loading;
    this.template_status = '';
    this.templates = []; // setup template cache
    // get the globals from the div
    window.PEATCMS_globals = {};
    if ((el = document.getElementById('peatcms_globals')) && (data_set = el.dataset)) {
        //console.warn(data_set);
        // todo BUG apparently there can be errors here, we don’t want bloembraaden to stop execution entirely then
        for (attr in data_set) {
            if (data_set.hasOwnProperty(attr)) {
                window.PEATCMS_globals[attr] = JSON.parse(data_set[attr]);
            }
        }
        PEATCMS.removeNode(el);
        console.log('Globals are loaded');
        //console.warn(window.PEATCMS_globals);
    } else {
        console.error('Globals are missing');
    }
    if (typeof NAV !== 'function') {
        NAV = new PEATCMS_navigator(window.PEATCMS_globals.root);
    }
    if (typeof window.PEATCMS_globals.peatcms_last_clicked_node === 'undefined') {
        window.PEATCMS_globals.peatcms_last_clicked_node = null;
        window.addEventListener('mousedown', function (event) {
            window.PEATCMS_globals.peatcms_last_clicked_node = event.target;
        });
    }
    // prevent accidental window unload
    window.addEventListener('beforeunload', function (event) {
        // TODO make the posting ajax calls even when window unloads
        if (NAV.ajaxShouldBlock()) {
            event.preventDefault();
            // Chrome requires returnValue to be set
            event.returnValue = '';
        }
    });
    // load the session
    if ((session = window.PEATCMS_globals.session)) {
        this.session = session; // this is the vars with their values and respective timestamps from the server
    } else {
        console.error('No session found');
        this.session = {};
    }

    function setupPageOnce() {
        // switch linked styles (e.g. fonts) from preload to style
        const elements = document.getElementsByTagName('link')
        let el, i, len;
        for (i = 0, len = elements.length; i < len; ++i) {
            el = elements[i];
            if (el.hasAttribute('rel') && 'preload' === el.getAttribute('rel')) {
                el.setAttribute('rel', 'stylesheet');
            }
        }
        // load the tracking_id and recaptcha server_values
        if ((window.PEATCMS_globals.hasOwnProperty('google_tracking_id'))) {
            if ((self.google_tracking_id = PEATCMS.trim(window.PEATCMS_globals.google_tracking_id)) !== '') {
                if (typeof CMS_admin === 'undefined') {
                    self.setup_google_tracking(self.google_tracking_id);
                } else {
                    console.warn('Google tracking not setup for admin');
                }
            }
            delete window.PEATCMS_globals.google_tracking_id;
        }
        if ((window.PEATCMS_globals.hasOwnProperty('recaptcha_site_key'))) {
            if ((self.recaptcha_site_key = PEATCMS.trim(window.PEATCMS_globals.recaptcha_site_key)) !== '') {
                self.setup_google_recaptcha(self.recaptcha_site_key);
            }
            delete window.PEATCMS_globals.recaptcha_site_key;
        }
    }

    function setupAddress() {
        // enhance the inputs of an address thingie, throttle updating and check on the server to save and for postcode_nl: only fill in missing values when empty (=once)
        const elements = document.querySelectorAll('[data-peatcms_enhance_address]');
        let el, i, len;
        for (i = 0, len = elements.length; i < len; ++i) {
            el = elements[i]; // this is a container holding address elements you want to enhance
            el.Address = new Address(el);
            // addresses in wrappers can be present as sessionvars!
            if (false === el.hasAttribute('id')) {
                console.error('Address wrapper element needs a unique id');
            } else {
                el.Address.updateClientOnly(PEAT.getSessionVar(el.id));
            }
        }
    }

    if (this.document_status < this.status_codes.ready) {
        this.addEventListener('peatcms.document_ready', setupPageOnce, true);
    } else {
        setupPageOnce();
        setupAddress();
    }
    this.addEventListener('peatcms.document_ready', setupAddress);
    // Load the nodes that are in the head to update dynamically
    this.cached_head_nodes = this.loadHeadNodes(document.head.childNodes);
    if (VERBOSE) console.log('... peatcms started');
    // make stylesheet manipulation easier
    style = document.createElement('style');
    // style.setAttribute("media", "screen")
    // style.setAttribute("media", "only screen and (max-width: 1024px)")
    style.appendChild(document.createTextNode('')); // WebKit hack :(
    style.id = 'peatcms_dynamic_css';
    document.head.appendChild(style);
    this.stylesheet = new PEAT_style(style.sheet);
}
PEATCMS.prototype.setStyleRule = function (selector, rule) {
    this.stylesheet.upsertRule(selector, rule);
}
PEATCMS.prototype.lastClicked = function () {
    return window.PEATCMS_globals.peatcms_last_clicked_node;
}
PEATCMS.prototype.copyToClipboard = function (str) {
    var success = false,
        selected,
        el = document.createElement('textarea'); // Create a <textarea> element
    el.setAttribute('readonly', 'true'); // Make it readonly to be tamper-proof
    el.setAttribute('contenteditable', 'true'); // for ios
    el.style.position = 'absolute';
    el.style.left = '-9999px'; // Move outside the screen to make it invisible
    el.value = str; // Set its value to the string that you want copied
    document.body.appendChild(el); // Append the <textarea> element to the HTML document
    selected =
        document.getSelection().rangeCount > 0 // Check if there is any content selected previously
            ? document.getSelection().getRangeAt(0) // Store selection if found
            : false; // Mark as false to know no selection existed before
    el.select(); // Select the <textarea> content
    if (document.execCommand('copy')) {
        success = true;
    } // Copy - only works as a result of a user action (e.g. click events)
    if (success === false) { // try something else (iOS, ipad....)
        var range = document.createRange();
        range.selectNodeContents(el);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        el.setSelectionRange(0, 999999);
        if (document.execCommand('copy')) {
            success = true;
        } // Copy - only works as a result of a user action (e.g. click events)
    }
    document.body.removeChild(el); // Remove the <textarea> element
    if (selected) { // If a selection existed before copying
        document.getSelection().removeAllRanges(); // Unselect everything on the HTML document
        document.getSelection().addRange(selected); // Restore the original selection
    }
    return success;
}

/**
 * Mimics document.addEventListener but for peatcms custom events there is the option of calling them once.
 * @param type Any type, but only peatcms types are allowed if you want to execute once
 * @param listener Callable function
 * @param once Default false, when true the listener will be called when the event triggers and immediately removed
 */
PEATCMS.prototype.addEventListener = function (type, listener, once) {
    var id;
    if (true === once) {
        if (peatcms_events.includes(type)) {
            id = PEATCMS.numericHashFromString(listener.toString());
            if (!this.eventListeners[type]) this.eventListeners[type] = {};
            this.eventListeners[type][id] = listener;
        } else {
            console.error('This type cannot be called ‘once’: ' + type);
        }
    } else {
        document.addEventListener(type, listener);
    }
}

/**
 * This method is added to all peatcms custom events, when called with an event, it will lookup the listeners
 * for that event type and call them once + remove from the list for that event.
 * @param e Event
 */
PEATCMS.prototype.triggerEvent = function (e) {
    var id, obj;
    if ((obj = this.eventListeners[e.type])) {
        for (id in obj) {
            if (obj.hasOwnProperty(id)) {
                obj[id].call(e.type, e); /* TODO bizarre, you need to send the event as the second arg or it will be undefined?? */
                delete (obj[id]);
            }
        }
    }
}
PEATCMS.prototype.setup_google_recaptcha = function (site_key) {
    var script = document.createElement('script'),
        div = document.createElement('div');
    if (VERBOSE) console.log('Setting up Google recaptcha with key: ' + site_key);
    div.id = 'recaptcha';
    div.classList.add('g-recaptcha');
    div.setAttribute('data-size', 'invisible'); // size: invisible please
    div.setAttribute('data-sitekey', site_key);
    div.setAttribute('data-peatcms-keep', 'permanent');
    document.body.appendChild(div);
    document.head.appendChild(script);
    script.id = 'google_recaptcha';
    script.setAttribute('nonce', window.PEATCMS_globals.nonce);
    //script.src = 'https://www.google.com/recaptcha/api.js?render=' + site_key; <- this will render itself independently
    script.src = 'https://www.google.com/recaptcha/api.js'; // <- this renders in the first .g-recaptcha it finds
}
/*if (($recaptcha_site_key = $this->instance->getSetting('recaptcha_site_key')) !== '') {
    $html .= '<div id="recaptcha" class="g-recaptcha" data-size="invisible" data-sitekey="'.
        $recaptcha_site_key . '" data-peatcms-keep="permanent"></div>';
    $html .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}*/

PEATCMS.prototype.setup_google_tracking = function (google_tracking_id) {
    // load google tag tracking, if requested
    // regarding pageview tracking and events check this:
    // https://developers.google.com/analytics/devguides/collection/gtagjs/pages
    if (typeof google_tracking_id !== 'string') {
        // TODO setup pagetracking for multiple tracking ids
        if (null === google_tracking_id) {
            if (VERBOSE) console.log('No Google tracking id');
        } else {
            console.warn('Multiple Google tracking ids not yet supported');
            console.log(google_tracking_id);
        }
    } else {
        if (google_tracking_id.indexOf('GTM-') === 0) {
            if (VERBOSE) console.log('Setting up Google tag manager with id: ' + google_tracking_id);
            // put the recommended gtm loading function in here, MODIFIED regarding the nonce
            (function (w, d, s, l, i) {
                w[l] = w[l] || [];
                w[l].push({'gtm.start': new Date().getTime(), event: 'gtm.js'});
                var f = d.getElementsByTagName(s)[0], j = d.createElement(s), dl = l != 'dataLayer' ? '&l=' + l : '';
                j.async = true;
                j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
                j.setAttribute('nonce', window.PEATCMS_globals.nonce);
                f.parentNode.insertBefore(j, f);
            })(window, document, 'script', 'dataLayer', google_tracking_id);
        } else if (google_tracking_id.indexOf('G-') === 0) {
            if (VERBOSE) console.log('Setting up Google analytics gtag with id: ' + google_tracking_id);
            // setup datalayer and MANDATORY gtag function
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () {
                dataLayer.push(arguments);
            }
            gtag('js', new Date());
            gtag('config', google_tracking_id, {'send_page_view': false}); // pageview is sent on navigation_end

            function do_the_doodoo() {
                if (NAV.is_navigating) return;
                if (VERBOSE) console.log('gtag: page_view', decodeURI(document.location.href));
                gtag('event', 'page_view', {
                    page_title: document.title,
                    page_location: decodeURI(document.location.href)
                });
            }

            // load the gtag script, nonce is necessary because of CSP
            var script = document.createElement('script');
            document.head.appendChild(script);
            script.setAttribute('nonce', window.PEATCMS_globals.nonce);
            script.id = 'google_gtag';
            //script.addEventListener('load', do_the_doodoo);
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + google_tracking_id;
            document.addEventListener('peatcms.navigation_end', do_the_doodoo);
        } else {
            console.error(google_tracking_id + ' not recognized as a Google tracking id');
        }
    }
}

PEATCMS.prototype.render = function (element, callback) {// don't rely on element methods, it could have been serialized
    let self = this,
        out = PEATCMS.cloneShallow(element.state), // break reference (or else the element.state.template_pointer would get changed)
        template_pointer = out.hasOwnProperty('template_pointer') ? out.template_pointer : {
            'name': 'home',
            'admin': false
        },
        template_name = template_pointer['name'],
        admin = template_pointer['admin'],
        // TODO as of 0.5.5 the templates are loaded by id when present, fallback to the old way
        template_cache_name = (out.template_id && out.type !== 'template') ? out.template_id : template_name + '_' + admin,
        template = false,
        html, cached_nodes, child_nodes, child_node, child_node_html, new_nodes, new_node, new_node_html, node_walker,
        len, el, data, property_id, attributes, attribute,
        node_unique_qualifier,
        // meta name, meta http-equiv and meta property can only be in the head once (per name and property)
        // rel=canonical may only be in the head once as well
        only_once = {
            "name": true,
            "http-equiv": true,
            "property": true,
            "rel": "canonical"
        }, key, old_property_id, i, i2, i2_len, i2_found = false;
    // set status
    if (this.document_status !== this.status_codes.rendering) {
        this.document_status = this.status_codes.rendering;
        document.dispatchEvent(new CustomEvent('peatcms.document_rendering')); // send without detail, does not bubble
    }
    // load template if not in cache
    if (!this.templates[template_cache_name]) {
        if (VERBOSE) console.log('Loading template ' + template_cache_name);
        // TODO as of 0.5.5 the templates are loaded by id when present with fallback to the old way
        data = {'template_id': template_cache_name, 'template_name': template_name, 'admin': admin};
        NAV.ajax('/__action__/get_template/', data, function (data) {
            if (!data['__html__']) {
                console.error('Loading template ‘' + template_name + '’ failed');
                self.ajaxifyDOMElements(document); // ajaxify the static thing, and send document_ready none the less...
                document.dispatchEvent(new CustomEvent('peatcms.document_ready', {
                    bubbles: true,
                    detail: {
                        title: out.title,
                        slug: out.slug,
                        path: out.path
                    }
                }));
            } else {
                self.templates[template_cache_name] = new PEATCMS_template(data);
                self.render(element, callback);
            }
        });
        return false;
    } else {
        template = this.templates[template_cache_name];
        if (VERBOSE) {
            console.log('Templator ‘' + template_cache_name + '’ from cache:');
            console.log(template);
        }
        // cache which template is currently active
        this.template_cache_name = template_cache_name;
    }
    //this.template_cache_name = template_cache_name;
    data = template['template']['__template_status__'] || '';
    // TODO temporary fix, integrate this with live hints for admin
    var checkers = document.getElementsByClassName('template_status');
    for (i = 0; i < checkers.length; ++i) {
        checkers[i].innerText = data;
    }
    // setup some global values
    data = window.PEATCMS_globals;
    for (i in data) {
        if (data.hasOwnProperty(i)) {
            out[i] = data[i];
        }
    }
    data = null;
    if ((html = template.render(out))) {
        this.title = template.getInnerHtml('title', html);
        // html is clean html, you can use that to fill head and body here
        this.loderunner = 1; // checks loading of all assets, 1 = for document that will be registered as loaded as well
        // start with loading head nodes
        document.title = this.title;
        // update the head meta, link, script, style and noscript nodes
        new_nodes = this.loadHeadNodes(template.getChildNodes('head', html));
        cached_nodes = this.cached_head_nodes;
        for (property_id in new_nodes) { // property_id is actually the id of the node in the head
            if (new_nodes.hasOwnProperty(property_id) && false === cached_nodes.hasOwnProperty(property_id)) {
                new_node = new_nodes[property_id];
                for (key in only_once) {
                    if ((node_unique_qualifier = new_node.getAttribute(key))) {
                        if (only_once[key] === true || only_once[key] === node_unique_qualifier) {
                            // check if there is one like this in the dom, if so remove it
                            for (old_property_id in cached_nodes) {
                                if (true === cached_nodes.hasOwnProperty(old_property_id)) {
                                    //console.warn(old_property_id);
                                    if (node_unique_qualifier === cached_nodes[old_property_id].getAttribute(key)) {
                                        //if (VERBOSE) console.log('Removing ' + old_property_id +
                                        //    ' because only one ' + node_unique_qualifier + ' is allowed');
                                        if ((el = document.getElementById(old_property_id))) document.head.removeChild(el);
                                        // don't need this anymore, will be replaced later
                                        delete cached_nodes[old_property_id];
                                    }
                                }
                            }
                        }
                    }
                }
                if (new_node.nodeName.toLowerCase() === 'script' && new_node.hasAttribute('src')) {
                    // loading of javascripts is different
                    // https://stackoverflow.com/questions/16230886/trying-to-fire-the-onload-event-on-script-tag
                    self.loderunner++;
                    let scr = document.createElement('script');
                    scr.id = new_node.id;
                    scr.className = new_node.className;
                    document.head.appendChild(scr);
                    scr.addEventListener('load', function () {
                        self.registerAssetLoad(this);
                    });
                    scr.setAttribute('src', new_node.getAttribute('src'));
                } else { // you can safely just add it
                    document.head.appendChild(new_node);
                }
            }
        }
        // throw the nodes away to prevent memory leaks since they are a documentfragment in the dom
        new_nodes = null;
        // reload headNodes
        this.cached_head_nodes = this.loadHeadNodes(document.head.childNodes);
        var originals, new_attrs = {};
        // set attributes of the body
        if ((attributes = template.getAttributes('body', html))) {
            for (i = 0, len = attributes.length; i < len; ++i) {
                attribute = attributes[i];
                new_attrs[attribute.nodeName] = attribute.nodeValue;
                document.body.setAttribute(attribute.nodeName, attribute.nodeValue);
            }
        }
        // remove any attributes that were not sent
        if ((originals = document.body.getAttributeNames())) {
            for (i = 0, len = originals.length; i < len; i++) {
                if (!new_attrs[originals[i]]) document.body.removeAttribute(originals[i]);
            }
        }
        new_attrs = null;
        originals = null;
        attributes = null;

        // remove nodes from body except the ones designated as permanent, remove those from the new html
        // only works with direct children from the body obviously, deeper nodes cannot remain in dom without parents
        // TODO maybe you can refactor this into a react like thingie that just updates nodes that have changed
        child_nodes = document.body.childNodes;
        len = child_nodes.length;
        new_nodes = template.getChildNodes('body', html);
        // @since 0.9.4: only body is relevant for rendering, forget anything that might be in the head
        html = template.getInnerHtml('body', html);
        node_walker = new_nodes.length - 1;
        for (i = len - 1; i >= 0; i--) {
            child_node = child_nodes[i];
            if (child_node.getAttribute && child_node.getAttribute('data-peatcms-keep') !== null) {
                var attr_name = child_node.getAttribute('data-peatcms-keep');
                if (html.indexOf('data-peatcms-keep="' + attr_name + '"') !== -1) {
                    //console.log('load all new nodes from the last until ' + attr_name);
                    while ((new_node = new_nodes[node_walker])) {
                        if (!new_node.getAttribute) {
                            if (new_node.nodeName === '#text') { // don't bother copying comments, but copy plain text nodes
                                child_node.insertAdjacentHTML('afterend', new_node.textContent);
                            }
                            new_node.parentNode.removeChild(new_node);
                            node_walker--;
                            continue;
                        }
                        if (new_node.getAttribute('data-peatcms-keep') === attr_name) {
                            new_node.parentNode.removeChild(new_node);
                            node_walker--;
                            break;
                        } else {
                            child_node.insertAdjacentElement('afterend', new_node);
                            node_walker--;
                        }
                    }
                } else if ('permanent' !== attr_name) {
                    // console.warn('remove ' + attr_name);
                    document.body.removeChild(child_node);
                }
            } else if ((child_node_html = child_node.innerHTML) && child_node_html.indexOf('<iframe') > -1) {
                // TODO OK for now: it works with re-rendering if you're not an admin
                // TODO for the sake of the sanity: remove the edit stuff while comparing so it works for admins as well
                // prevent nodes with iframes to reload if they did not change (especially during re-render on the frontend)
                // walk through the new nodes to find the same innerHTML
                for (i2_len = new_nodes.length, i2 = i2_len - 1; i2 >= 0; i2--) {
                    /*if (new_nodes[i2].innerHTML) {
                        console.warn(i2 + ' opa iteration checking html with iframe');
                        console.log(new_nodes[i2].innerHTML);
                        console.log(child_node_html);
                    }*/
                    if ((new_node_html = new_nodes[i2].innerHTML) && new_node_html.indexOf(child_node_html) > -1) {
                        //console.warn('opa heeft een node met iframe(s) gevonden om te bewaren');
                        i2_found = true;
                        break;
                    }
                }
                if (true === i2_found) {
                    // TODO somewhat duplicate code from above (data-peatcms-keep part)
                    while ((new_node = new_nodes[node_walker])) {
                        if (!new_node.getAttribute) {
                            if (new_node.nodeName === '#text') { // don't bother copying comments, but copy plain text nodes
                                child_node.insertAdjacentHTML('afterend', new_node.textContent);
                            }
                            new_node.parentNode.removeChild(new_node);
                            node_walker--;
                            continue;
                        }
                        if (new_node.innerHTML.indexOf(child_node_html) > -1) { // TODO optimize this some more, you should know the index
                            new_node.parentNode.removeChild(new_node);
                            node_walker--;
                            break;
                        } else {
                            child_node.insertAdjacentElement('afterend', new_node);
                            node_walker--;
                        }
                    }
                } else {
                    document.body.removeChild(child_node);
                }
            } else {
                document.body.removeChild(child_node);
            }
            //if (node_walker === 0) break; // you should never reach this problematically
        }
        // load the remaining new nodes
        for (i = node_walker; i >= 0; --i) {
            if ((new_node = new_nodes[i])) {
                if (!new_node.getAttribute) {
                    if (new_node.nodeName === '#text') { // also set the text, don't bother copying comments
                        document.body.insertAdjacentHTML('afterbegin', new_node.textContent);
                    }
                    continue;
                }
                document.body.insertAdjacentElement('afterbegin', new_node);
            }
        }
        new_nodes = null; // prevent memory leaks
        this.ajaxifyDOMElements();
        if (typeof CMS_admin !== 'undefined') {
            // hide the edit buttons when current element is not editable (admin cannot use IE)
            document.querySelectorAll('[data-peatcms_handle="edit_current"]').forEach(function (el) {
                el.setAttribute('data-disabled', (element.isEditable()) ? '0' : '1');
            });
        }

        // @since 0.6.11 check if you switched from type of template to initialize the site again
        var temp = template.template;
        if (temp.hasOwnProperty('__template_status__') && temp.__template_status__ === 'default') {
            this.template_status = 'default';
        } else if (this.template_status === 'default') {
            this.template_status = '';
            if (VERBOSE) console.warn('Sending initialize event again because of switching template types');
            document.dispatchEvent(new CustomEvent('peatcms.initialize'));
        }
        // callback
        if (typeof callback === 'function') {
            callback(element);
        }
        this.document_status = this.status_codes.ready;
        document.dispatchEvent(new CustomEvent('peatcms.document_ready', {
            bubbles: true,
            detail: {
                title: out.title,
                slug: out.slug,
                path: out.path
            }
        }));
        // for any remaining tags in the body, render them progressively
        template.renderProgressive(); // document.body.innerHTML
        this.registerAssetLoad(document); // we're loaded!
    } else {
        console.error('Templator could not render output');
    }
    //return this.DOMElement;
}
PEATCMS.prototype.renderProgressive = function (tag, slug) // pass the slug to the currently active template to render, if applicable
{
    var template;
    if (!slug) slug = tag; // @since 0.5.15 you can render a different slug in a tag progressively
    if (this.hasOwnProperty('template_cache_name') && (template = this.templates[this.template_cache_name])) {
        template.renderProgressive(tag, slug);
    } else {
        console.error('No current template found');
    }
}

PEATCMS.prototype.registerAssetLoad = function () {
    let self = this;
    this.loderunner--;
    // todo optimise this a bit more, this is a poc
    if (0 === this.loderunner && this.document_status !== this.status_codes.complete) {
        if ('complete' === document.readyState) {
            self.document_status = self.status_codes.complete;
            document.dispatchEvent(new CustomEvent('peatcms.document_complete')); // send without detail, does not bubble
        } else {
            window.addEventListener('load', function() {
                self.document_status = self.status_codes.complete;
                document.dispatchEvent(new CustomEvent('peatcms.document_complete')); // send without detail, does not bubble
           });
        }
    }
}

PEATCMS.prototype.loadHeadNodes = function (head_nodes) {
    var obj = {}, head_node, i;
    for (i = 0; i < head_nodes.length; ++i) {
        if (head_nodes.hasOwnProperty(i)) {
            head_node = head_nodes[i];
            if (['TITLE', '#text', '#comment'].includes(head_node.nodeName)) continue;
            // use hashcode to id nodes that didn't change, so you don't update them
            if (!head_node.id) head_node.id = PEATCMS.numericHashFromString(head_node.outerHTML);
            obj[head_node.id] = head_node;
        }
    }
    return obj;
}

PEATCMS.prototype.findDataStasherInDOM = function (DOMElement, table_name) {
    // go to all previous siblings, then parent then again previous siblings, etc. until you find element with className
    var el = DOMElement,
        className = 'PEATCMS_data_stasher';
    while (true) {
        if (el.previousSibling) {
            el = el.previousSibling;
        } else if (el.parentElement) {
            el = el.parentElement;
        } else {
            console.error(className + ' not found for DOMElement:');
            console.log(DOMElement);
            return false;
        }
        if (typeof el.classList === 'undefined') continue;
        if (el.classList.contains(className)) {
            if (el.getAttribute('data-table_name') === table_name) break;
        }
    }
    return el;
}
PEATCMS.prototype.swipifyDOMElement = function (el, on_swipe_left, on_swipe_right) {
// Detect swipe events for touch devices, credit to Kirupa @ https://www.kirupa.com/html5/detecting_touch_swipe_gestures.htm
    var initialX = null;
    var initialY = null;

    var startTouch = function (e) {
        initialX = e.touches[0].clientX;
        initialY = e.touches[0].clientY;
    }

    var moveTouch = function (e) {
        if (initialX === null) {
            return;
        }
        if (initialY === null) {
            return;
        }
        var diffX = initialX - e.touches[0].clientX;
        var diffY = initialY - e.touches[0].clientY;
        if (Math.abs(diffX) > 50 && Math.abs(diffX) > Math.abs(diffY)) {
            if (diffX > 0) {// swiped left
                on_swipe_left(e);
            } else {// swiped right
                on_swipe_right(e);
            }
        } else {
            window.scrollBy(diffY);
        }
        initialX = null;
        initialY = null;
        //e.preventDefault(); // <- doesn't work with passive...
    }

    el.addEventListener('touchstart', startTouch, {passive: true});
    el.addEventListener('touchmove', moveTouch, {passive: true});
}

PEATCMS.prototype.ajaxNavigate = function (e) {
    if (e.ctrlKey === false) { // opening in new tab / window should still be possible
        e.preventDefault();
        e.stopPropagation();
        return NAV.go(this.getAttribute('data-peatcms_href'));
    }
}

PEATCMS.prototype.ajaxMailto = function () {
    window.location.href = 'mailto:' + this.innerHTML;
}

PEATCMS.prototype.ajaxSubmit = function (e) {
    var submit_button, submit_msg; // the form
    e.preventDefault();
    e.stopPropagation();
    if (this.hasAttribute('data-submitting')) {
        console.warn('Already submitting');
        return false;
    }
    this.setAttribute('data-submitting', '1');
    // @since 0.7.9 allow confirmation question on submit button
    if ((submit_button = this.querySelector('[type="submit"]'))) {
        if (submit_button.getAttribute('data-confirm') &&
            null !== (submit_msg = submit_button.getAttribute('data-confirm'))) {
            if (false === confirm(submit_msg)) {
                this.removeAttribute('data-submitting');
                return false;
            }
        }
    }
    // submit the form
    this.dispatchEvent(new CustomEvent('peatcms.form_posting', {
        bubbles: true,
        detail: {
            "form": this
        }
    }));
    NAV.submitForm(this);
    return false;
}

PEATCMS.prototype.ajaxifyDOMElements = function (el) {
    var self = this, forms, form, as, a, i, len, sibling, stasher, parent_name;
    if (el) {
        if (!el instanceof Element) {
            console.error(el, 'must be a DOMElement');
            return;
        }
    } else {
        el = document.body; // defaut to all over body program
    }
    // update links
    as = el.getElementsByTagName('a');
    // TODO this renders relative links relative to the PREVIOUS slug, which does not work great
    for (i = 0, len = as.length; i < len; i++) {
        a = as[i];
        if (true === a.hasAttribute('target')) continue;
        // make sure to add the eventlistener only once, or each click will generate multiple ajax calls over time
        a.removeEventListener('click', self.ajaxNavigate);
        a.addEventListener('click', self.ajaxNavigate);
        a.setAttribute('data-peatcms_href', a.href);
    }
    // update forms
    forms = el.getElementsByTagName('form');
    for (i = 0, len = forms.length; i < len; i++) {
        form = forms[i];
        if (false === form.hasAttribute('action')) {
            console.error('This form has no action', form);
            continue;
        }
        if (false === form.hasAttribute('data-peatcms_ajaxified')) {
            form.setAttribute('data-peatcms_ajaxified', '1');
            // method defaults to post
            if (false === form.hasAttribute('method')) form.setAttribute('method', 'post');
            form.removeEventListener('submit', self.ajaxSubmit);
            form.addEventListener('submit', self.ajaxSubmit);
        }
    }
    // fix e-mail links:
    as = el.getElementsByClassName('peatcms-email-link')
    for (i = 0, len = as.length; i < len; ++i) {
        a = as[i];
        a.removeEventListener('click', self.ajaxMailto);
        a.addEventListener('click', self.ajaxMailto);
        if (a.classList.contains('peatcms-link')) continue;
        a.innerHTML = PEATCMS.replace('-dot-', '.', PEATCMS.replace('-at-', '@', a.innerHTML));
        a.classList.add('peatcms-link');
        a.classList.add('link');
        a.setAttribute('tabindex', '0');
    }
    if (typeof CMS_admin !== 'undefined') {
        as = el.getElementsByClassName('PEATCMS_edit_button');
        // render edit buttons / regions
        // for (i = as.length - 1; i >= 0; i--) {
        //     a = as[i];
        //     sibling = a.nextSibling;
        //     // TODO what when it's a textnode or something that doesn't accept setAttribute
        //     if (typeof sibling.setAttribute === 'function') {
        //         sibling.setAttribute('data-peatcms_slug', a.getAttribute('data-peatcms_slug'));
        //         sibling.id = el.getAttribute('data-peatcms_id');
        //         sibling.addEventListener('mouseover', function () {
        //             if (CMS_admin) {
        //                 this.classList.add('hovering');
        //                 CMS_admin.showEditMenu(this);
        //             }
        //         });
        //         sibling.addEventListener('mouseout', function () {
        //             this.classList.remove('hovering');
        //         });
        //     }
        //     a.remove();
        // }
        // if this is an administration page, some elements are directly editable
        as = el.getElementsByClassName('PEATCMS_editable');
        for (i = as.length - 1; i >= 0; i--) {
            if ((a = as[i]).hasAttribute('data-peatcms_ajaxified')) continue;
            a.setAttribute('data-peatcms_ajaxified', '1');
            if (a.getAttribute('data-peatcms_handle') === 'new_row') {
                // TODO this is so messy, I'm sure you can do better
                // you should know the parent, and then make it multiple and look for the tag
                // if you know it multiple, then you also know the template location etc.
                if ((parent_name = a.getAttribute('data-table_parent'))) {
                    if ((stasher = this.findDataStasherInDOM(a, parent_name))) {
                        a.setAttribute('data-table_parent_id', stasher.getAttribute('data-peatcms_id'));
                    }
                }
                a = new PEATCMS_column_updater(a);
            } else { // for manipulating rows you need the data-peatcms_id
                if ((stasher = this.findDataStasherInDOM(a, a.getAttribute('data-table_name')))) {
                    a.setAttribute('data-peatcms_id', stasher.getAttribute('data-peatcms_id'));
                    // make editable / input
                    a = new PEATCMS_column_updater(a);
                }
            }
        }
    }
}

PEATCMS.prototype.currentSlugs = function (element) {
    var i, len, a, href, el = element || document,
        as = el.getElementsByTagName('a'), // faster than querySelectorAll()
        current_slug = NAV.getCurrentPath() + '|', // the pipe character is to match wordboundary, so you match the entire slug
        root = NAV.getRoot(true);
    for (i = 0, len = as.length; i < len; ++i) {
        a = as[i];
        href = PEATCMS.replace(root, '', decodeURI(a.href)) + '|'; // match the exact slug, not when it’s a part of the link
        if (current_slug === href) {
            a.classList.add('peatcms-current-slug');
            a.setAttribute('aria-current', 'location');
        } else {
            a.classList.remove('peatcms-current-slug');
            a.removeAttribute('aria-current');
        }
    }
}

PEATCMS.prototype.startUp = function () {
    var i, len, self = this;
    this.html_node = document.getElementsByTagName('html')[0];
    //var IE11 = !!window.msCrypto;
    //console.error('startUp short circuited for now');
    //return;
    // add js flag to html
    this.html_node.classList.add('js');
    // handle no hover flag for body
    document.body.addEventListener('touchstart', function () { // remove hover state
        this.classList.add('no-hover');
    }, {passive: true});
    document.body.addEventListener('mouseenter', function () { // restore hover state
        this.classList.remove('no-hover');
    });
    /**
     * add listeners for normal functioning
     */
    for (i = 0, len = peatcms_events.length; i < len; ++i) {
        document.addEventListener(peatcms_events[i], function (e) {
            self.triggerEvent(e);
        });
    }
    // default closing mechanism for messages
    document.addEventListener('peatcms.message', function (e) {
        var el = e.detail.element,
            closeButton = document.createElement('div'); // dismiss button
        closeButton.classList.add('button');
        closeButton.classList.add('close');
        closeButton.onclick = function () {
            PEATCMS.removeNode(this.parentNode);
        };
        el.appendChild(closeButton);
        if (el.classList.contains('log')) { // log messages disappear soon
            window.setTimeout(function (el) {
                PEATCMS.removeNode(el);
            }, 3000, el);
        }
    });
    // default handling of the active (menu) items
    document.addEventListener('peatcms.document_ready', function (e) {
        self.currentSlugs(document);
        self.setScrolledStatus();
    });
    // default of the progressbar
    document.addEventListener('peatcms.navigation_start', function () {
        let loading_bar;
        if ((loading_bar = document.getElementById('peatcms_loading_bar'))) {
            loading_bar.style.width = '0';
            loading_bar.setAttribute('aria-valuemin', '0');
            loading_bar.setAttribute('aria-valuemax', '100');
        }
    });
    // send one-time peatcms event peatcms.initialize
    if (VERBOSE) console.log('Emitting one-time event peatcms.initialize');
    document.dispatchEvent(new CustomEvent('peatcms.initialize'));
    // record first page in history
    NAV.refresh(); // also Re-renders the page for full use
    // handle messages
    //this.messages(this.session.messages);
    this.messages(window.PEATCMS_globals['__messages__']);
    // @since 0.7.1 remember scroll position for when the user returns
    window.addEventListener('scroll', function () {
        // throttle updating
        clearTimeout(this.setstate_timeout);
        this.setstate_timeout = setTimeout(function () {
            NAV.setState();
        }, 392);
        self.setScrolledStatus();
    });
}

PEATCMS.prototype.setScrolledStatus = function () {
    /* help styles with scrolling and stuff */
    if (window.pageYOffset > 4) { /* consider the page scrolled */
        this.html_node.setAttribute('data-peatcms-scrolled', '1');
    } else {
        this.html_node.removeAttribute('data-peatcms-scrolled');
    }
}
/**
 *
 * @param name
 * @returns {null|*}
 */
PEATCMS.prototype.getSessionVar = function (name) {
    var session = this.session;
    if (session && session.hasOwnProperty(name)) {
        return session[name].value;
    }
    return null;
}
/**
 * @param name
 * @param session_var
 * @since 0.6.1
 */
PEATCMS.prototype.updateSessionVarClientOnly = function (name, session_var) {
    var sess = this.session, times = 0;
    if (sess[name]) times = sess[name]['times'];
    if (session_var.times >= times) {
        sess[name] = session_var;
        if (VERBOSE) {
            console.log('Session var ‘' + name + '’ updated:');
            console.log(session_var);
        }
    } else {
        console.warn('Refused session var ‘' + name + '’ because it’s too old');
    }
}

PEATCMS.prototype.setSessionVar = function (name, value, callback) { // NOTE callback is only for ajax, so the server value is updated as well
    var sess = this.session, times = 0;
    if (!callback) callback = null;
    if (sess[name]) times = 1 + sess[name]['times'];
    sess[name] = {value: value, times: times}; // update it right away, affirm when it’s back from the server
    NAV.ajax('/__action__/set_session_var', {name: name, value: value, times: times}, function (data) {
        // ajax updates new session vars automatically, you dan’t have to do that here @since 0.6.1
        if (typeof callback === 'function') callback.call(null, value);
    });
}

PEATCMS.prototype.message = function (msg_obj, level) {
    var el, string = msg_obj.message || msg_obj, count = msg_obj.count || 1,
        message_wrapper, id;
    if (!level) level = 'log'; // replacing default value which is not supported < ES6
    id = PEATCMS.numericHashFromString(string) + '_message_' + level;
    if (!(message_wrapper = document.getElementById('message_wrapper'))) {
        message_wrapper = document.createElement('div');
        message_wrapper.id = 'message_wrapper';
        message_wrapper.setAttribute('data-peatcms-keep', 'permanent');
        document.body.insertAdjacentElement('afterbegin', message_wrapper);
    }
    if ((el = document.getElementById(id))) { // have it grab attention
        this.grabAttention(el);
    } else {
        el = document.createElement('div');
        el.classList.add('PEATCMS');
        el.classList.add('message');
        el.classList.add(level);
        el.innerHTML = string;
        if (count > 1) {
            el.insertAdjacentHTML('beforeend', '<span class="count">' + count.toString() + '</span>');
        }
        el.id = id;
        message_wrapper.insertAdjacentElement('afterbegin', el);
    }
    el.dispatchEvent(new CustomEvent('peatcms.message', {
        bubbles: true,
        detail: {
            element: el
        }
    }));
    el = null;
}

PEATCMS.prototype.messages = function (data) {
    var i, messages, message_id;
    if (Array.isArray(data)) return; // this is a template
    for (i in data) {
        if (data.hasOwnProperty(i)) {
            messages = data[i];
            for (message_id in messages) {
                if (messages.hasOwnProperty(message_id)) this.message(messages[message_id], i)
            }
        }
    }
}
PEATCMS.prototype.grabAttention = function (DOMElement, low_key) {
    var class_name = low_key ? 'peatcms_signal_change' : 'peatcms_attention_grabber';
    DOMElement.classList.add(class_name);
    setTimeout(function (el, class_name) {
        el.classList.remove(class_name);
    }, 600, DOMElement, class_name);
}
PEATCMS.prototype.scrollIntoView = function (DOMElement, withMargin) {
    var rect, top, bottom, h, scroll_by = 0;
    if (!DOMElement) return;
    if (!withMargin) withMargin = 0;
    top = (rect = DOMElement.getBoundingClientRect()).top;
    bottom = top + rect.height;
    if (top < withMargin) {
        scroll_by = top - withMargin;
    } else if (bottom > (h = window.innerHeight)) {
        scroll_by = bottom - h + withMargin;
    }
    if ('scrollBehavior' in document.documentElement.style) {
        window.scrollBy({
            top: scroll_by,
            left: 0,
            behavior: 'smooth'
        })
    } else {
        window.scrollBy(0, scroll_by);
    }
}
PEATCMS.prototype.scrollToTop = function () {
    this.scrollTo(0, 0);
}
PEATCMS.prototype.scrollTo = function (x, y, element) {
    if (!element) element = window;
    if ('scrollBehavior' in document.documentElement.style) {
        element.scrollTo({
            left: x,
            top: y,
            behavior: 'smooth'
        });
    } else {
        if (element.scrollTo) {
            element.scrollTo(x, y);
        } else { // IE11...
            element.scrollLeft = x;
            element.scrollTop = y;
        }
    }
}

window.onpopstate = function (e) {
    var pos, path;
    if (e.state === null || !NAV) {
        if (!NAV) {
            console.error('Navigator not loaded on *pop*.');
        } else {
            console.error('No state given on *pop*.');
        }
        //document.location.reload(); // this keeps reloading on safari / iphone
    } else { // e.state holds max 640k so you can't put an element in it, for now it's just the slug and scrolling position
        path = e.state.path;
        NAV.signalStartNavigating(path);
        if (VERBOSE) console.log(e);
        // @since 0.7.1 restore scrolling position from history (after rendering)
        if (e.state.hasOwnProperty('scrollY') && (pos = {x: e.state.scrollX, y: e.state.scrollY})) {
            PEAT.addEventListener('peatcms.navigation_end', function () {
                window.scrollTo(pos.x, pos.y);
            }, true); // true means event is only executed once then removed
        }
        NAV.refresh(path);
    }
};

/**
 * The navigator object, instantiated later as global NAV
 */
//class PEATCMS_navigator extends PEATCMS_ajax(PEATCMS_base) {
var PEATCMS_navigator = function (root) {
    this.root = root; // the root is served without trailing slash
    this.element = false; // will load currently displayed element
    this.last_navigate = null; // remember if we went somewhere
    this.is_navigating = false;
    this.tags_in_cache = []; // setup tags cache
    this.tags_to_cache = ['__action__/countries'];
}
PEATCMS_navigator.prototype = new PEATCMS_ajax();
PEATCMS_navigator.prototype.tagsCache = function (tag, json) {
    if ('__' === tag.substr(0, 2) && false === this.tags_to_cache.includes(tag)) return;
    if (!json) {
        return this.tags_in_cache[tag] || null;
    }
    this.tags_in_cache[tag] = json;
}
PEATCMS_navigator.prototype.currentUrlIsLastNavigated = function (navigated_to) {
    var navigated_parts, current_parts, i;
    if (navigated_to) {
        this.last_navigate = navigated_to
    } else if (null === this.last_navigate) {
        return false;
    }
    navigated_parts = this.last_navigate.split('/');
    current_parts = this.getCurrentPath().split('/');
    for (i in navigated_parts) {
        if (false === navigated_parts.hasOwnProperty(i)) continue;
        if (false === current_parts.includes(navigated_parts[i])) return false;
    }
    return true;
}
PEATCMS_navigator.prototype.signalStartNavigating = function(path) {
    let slug = path.replace(this.getRoot(true), '');
    this.is_navigating = true; // there is no document_status navigating, for document_status is prop of PEAT, and we don't bleed over to that here
    if (0 === slug.indexOf('/')) slug = slug.substr(1);
    document.dispatchEvent(new CustomEvent('peatcms.navigation_start', {
        bubbles: false,
        detail: {
            slug: decodeURI(slug),
        }
    }));

    return slug;
}
PEATCMS_navigator.prototype.go = function (path, local) {
    var slug, self = this;
    if (!local) local = false; // replacing default value which is not supported < ES6
    if (window.history && window.history.pushState) {
        // @since 0.7.1 remember current scrolling position, overwrite the current setting in history
        this.setState();
        if (path.indexOf(this.getRoot()) === 0 || local === true) { // this is a local link
            slug = this.signalStartNavigating(path);
            new PEATCMS_element(slug, function (el) {
                var slug, title, path;
                if (el === false) {
                    console.error('The slug ‘' + slug + '’ is not an element');
                    document.dispatchEvent(new CustomEvent('peatcms.navigation_end'));
                    self.is_navigating = false;
                } else {
                    try {
                        if (el.state.hasOwnProperty('slug')) {
                            slug = el.state.slug;
                            title = el.state.title;
                            path = el.state.path || slug;
                            self.element = el; // cache current element
                            self.last_navigate = slug;
                            // data holds max 640k hence you can't put pages in it
                            window.history.pushState({
                                title: title,
                                path: path
                            }, title, '/' + path);
                            self.maybeEdit(slug);
                        }
                        PEAT.render(el, function (el) {
                            if (VERBOSE) console.log('Finished rendering ' + title);
                            self.is_navigating = false;
                            document.dispatchEvent(new CustomEvent('peatcms.navigation_end'));
                        });
                    } catch (e) {
                        self.is_navigating = false;
                        document.dispatchEvent(new CustomEvent('peatcms.navigation_end'));
                    }
                }
            });
        } else {
            window.location.href = path;
        }
        return false;
    }
    window.location.href = path;
}
PEATCMS_navigator.prototype.maybeEdit = function (slug) {
    // if edit screen is open, fill it with the element
    if (typeof CMS_admin === 'object') {
        if (CMS_admin.panels.get('sidebar').isOpen()) {
            if (!slug) slug = NAV.getCurrentSlug();
            CMS_admin.edit(slug);
        }
    }
}

PEATCMS_navigator.prototype.admin_uncache_slug = function (path, silent) {
    if (!path) path = this.getCurrentPath(); // replacing default value which is not supported < ES6
    this.ajax('/__action__/admin_uncache', {path: path, silent: !!silent}, function (json) {
        NAV.invalidateCache(); // throw away the cache now to be on the safe side
        NAV.refresh();
    });
}

// used for __user__ now @since 0.7.9
PEATCMS_navigator.prototype.reloadThenRefresh = function (tag) {
    // use ajax to update the tag, upon success refresh the page
    // TODO this is in anticipation of the template improvement that will handle this in renderProgressive
    NAV.ajax('/' + tag, '', function (json) {
        if (json.slug === tag && window.PEATCMS_globals[tag]) {
            window.PEATCMS_globals[tag] = json;
            NAV.refresh();
        }
    })
}

PEATCMS_navigator.prototype.refresh = function (path) {
    var self = this, globals = window.PEATCMS_globals, slug;
    if (!path) path = this.getCurrentPath(); // replacing default value which is not supported < ES6
    if (window.history && window.history.pushState) {
        if (globals.hasOwnProperty('slug')) { // move the first page’s slug into the cache
            slug = globals.slug;
            if (slug.hasOwnProperty('__ref')) {
                path = slug.__ref;
                if (slug.hasOwnProperty('variant_page')) {
                    path += '/variant_page' + slug.variant_page;
                }
            }
            if (VERBOSE) console.log('Put ‘' + path + '’ into the cache from globals');
            // cache is built into PEATCMS_ajax
            this.cache({state: unpack_temp(globals.slug)});
            delete globals.slug;
            PEAT.addEventListener('peatcms.document_ready', function () {
                delete window.PEATCMS_globals.slugs
            }, true);
            //delete globals.slugs;
        }
        new PEATCMS_element(path, function (el) {
            if (el === false) {
                console.error('Could not refresh');
            } else {
                PEAT.render(el, function (el) {
                    self.element = el; // cache current element
                    self.setState();
                    self.is_navigating = false;
                    document.dispatchEvent(new CustomEvent('peatcms.navigation_end')); // send without detail, does not bubble
                    //NAV.maybeEdit(); //JOERI
                });
            }
        });
    }
}

/**
 * updates the history state with scroll position
 * @since 0.7.1
 */
PEATCMS_navigator.prototype.setState = function () {
    var el;
    if (window.history && window.history.pushState) {
        if ((el = this.element)) {
            window.history.replaceState({
                path: el.state.path,
                title: el.state.title,
                scrollY: window.pageYOffset,
                scrollX: window.pageXOffset
            }, el.state.title, '/' + el.state.path);
            if (VERBOSE) console.log('State set regarding scroll position');
        } else {
            console.error('Could not set state, no element found');
            console.log(this);
        }
    }
}

PEATCMS_navigator.prototype.getRoot = function (trailingSlash) {
    return this.root + (trailingSlash ? '/' : '');
}

PEATCMS_navigator.prototype.getCurrentPath = function () { // returned path uri is clean, no extra slashes
    //return (document.location.href.replace(this.getRoot(true), '') + '/').replace(/\/\//g, '/'); // ensure uri always ends in a slash
    var href = decodeURIComponent(document.location.href.replace(this.getRoot(true), '')),
        len;
    if (href.indexOf('?') !== -1) href = href.split('?')[0];
    if (href.lastIndexOf('/') === (len = href.length - 1)) href = href.substr(0, len);
    return href;
}
PEATCMS_navigator.prototype.getCurrentUri = function () {
    return (PEATCMS_globals.root || '') + '/' + this.getCurrentPath();
}
PEATCMS_navigator.prototype.getCurrentSlug = function () {
    var slugs = this.getCurrentPath().split('/'),
        i;
    if (slugs.length === 1) {
        return decodeURIComponent(slugs[0]);
    } else {
        for (i = 0; i < slugs.length; i++) {
            if (slugs[i] === '__admin__') continue;
            if (slugs[i] === '__action__') continue;
            return decodeURIComponent(slugs[i]);
        }
    }
    return this.getCurrentElement().slug; // used to have decodeURIComponent around it?!
}

PEATCMS_navigator.prototype.getCurrentElement = function () {
    /*if (this.is_navigating) {
        console.error('Currently navigating, null returned, but this is the current state:');
        console.log(this.element.state);
        return null; // it consistently returns the current (near future) element, which is correct
    }*/
    return this.element;
}

PEATCMS_navigator.prototype.setCurrentElement = function (el) {
    this.element = el;
}

PEATCMS_navigator.prototype.addRecaptchaToData = function (data, callback) {
    // integrate recaptcha, but not for regular shoppinglist actions
    if (typeof grecaptcha !== 'undefined') {
        try {
            // TODO here it says you need to send some grecaptcha id in stead of the site key
            // https://developers.google.com/recaptcha/docs/faq
            // this id I have not been able to find, but it works with undefined as well (note: null does NOT work)
            // pretty weird behaviour, so need to check this and maybe make it ‘correct’ before this stops working
            grecaptcha.execute(undefined, {action: 'form_submit'}).then(function (token) {
                // set token in form
                data['g-recaptcha-token'] = token;
                callback(data);
            });
        } catch (thrown_error) {
            console.error(thrown_error);
            PEAT.message('Recaptcha error', 'error');
            callback(data)
        }
    } else {
        // submit the form through ajax
        callback(data);
    }
}
/**
 * Use submitData(string slug, object data, function callback)
 */
PEATCMS_navigator.prototype.postData = function () {
    //
}
PEATCMS_navigator.prototype.submitData = function (slug, data, callback) {
    var self = this;
    self.addRecaptchaToData(data, function (data) {
        self.ajax(slug, data, callback);
    });
}
/**
 * Use submitForm(HTMLElement form)
 */
PEATCMS_navigator.prototype.postForm = function () {
    //
}
PEATCMS_navigator.prototype.submitForm = function (form) {
    var self = this, data = PEATCMS.getFormData(form);
    // handle recaptcha if we’re not talking shoppinglist
    if (form.getAttribute('action').substr(0, 17) !== '/__shoppinglist__') {
        self.addRecaptchaToData(data, function (data) {
            self.submitFormData(form, data);
        });
    } else {
        self.submitFormData(form, data);
    }
}
PEATCMS_navigator.prototype.submitFormData = function (form, data) {
    this.ajax(form.getAttribute('action'), data, function (json) {
        var slug = data.hasOwnProperty('slug') ? data.slug : PEATCMS.trim(form.getAttribute('action'), '/'),
            event_data = { // nice data for the event after the form is posted
                bubbles: true,
                detail: {
                    "slug": slug, // superfluous, also in form
                    "data": data, // superfluous, also in form
                    "form": form,
                    "json": json,
                },
            };
        form.removeAttribute('data-submitting');
        if (json.hasOwnProperty('success') && true === json.success) {
            // reload the slug always
            // TODO too much rendering going on
            if (NAV.getCurrentPath() === slug) {
                NAV.refresh();
            } else {
                PEAT.renderProgressive(slug);
            }
            // emit events the designer can respond to
            form.dispatchEvent(new CustomEvent('peatcms.form_posted', event_data));
        } else {
            form.dispatchEvent(new CustomEvent('peatcms.form_failed', event_data));
        }
    }, form.getAttribute('method'));
}


/**
 * Stylesheet manipulator
 */
var PEAT_style = function (CSSStyleSheet) {
    // make sure you get the actual sheet, not the style thing itself
    if (CSSStyleSheet.sheet) CSSStyleSheet = CSSStyleSheet.sheet;
    this.CSSStyleSheet = CSSStyleSheet;
    this.rules = this.CSSStyleSheet.cssRules;
}

PEAT_style.prototype.getCurrentValue = function (selector, property) {
    var i, current_style, index = this.rules.length - 1;
    for (i = index; i >= 0; i--) {
        current_style = this.rules[i];
        if (current_style.selectorText === selector) {
            return current_style.style[property];
        }
    }
    return null;
}

PEAT_style.prototype.updateSelector = function (selector, html_id) {
    if (selector.indexOf('html') > -1) {
        selector = PEATCMS.replace('html', '', selector);
    }
    var str_rule = 'html#' + html_id + ' ';
    return str_rule + PEATCMS.replace(',', ',' + str_rule, selector);
}

PEAT_style.prototype.upsertCSSRule = function (cssRule, html_id) {
    var str_rule = cssRule.cssText,
        str_i = str_rule.indexOf('{'),
        selector, rule;
    // split into denominator and rule
    selector = str_rule.substring(0, str_i).trim();
    rule = str_rule.substring(str_i + 1).replace('}', '').trim();
    if (html_id) { // make all rules more specific to override the attached instance stylesheet
        return this.upsertRule(this.updateSelector(selector, html_id), rule); // returns the cssText
    }
    return this.upsertRule(selector, rule); // returns the cssText
}

PEAT_style.prototype.upsertRule = function (selector, rule) {
    //Backward searching of the selector matching cssRules
    // TODO this only hits once I think, fails if you have multiple definitions with the same selector (which is entirely possible)
    var i, current_style, index = this.rules.length;
    for (i = 0; i < index; ++i) {
        current_style = this.rules[i];
        if (current_style.selectorText === selector) {
            //Append the new rules to the current content of the cssRule;
            rule = current_style.style.cssText + rule;
            this.CSSStyleSheet.deleteRule(i);
            index = i;
            break;
        }
    }
    if (this.CSSStyleSheet.insertRule) {
        this.CSSStyleSheet.insertRule(selector + "{" + rule + "}", index);
    } else {
        this.CSSStyleSheet.addRule(selector, rule, index);
    }
    return this.CSSStyleSheet.cssRules[index].cssText;
}

PEAT_style.prototype.upsertRules = function (rules, html_id) {
    var rule, len;
    if (Array.isArray(rules)) { // CSSStyleRule array
        for (rule = 0, len = rules.length; rule < len; ++rule) {
            this.upsertCSSRule(rules[rule], html_id);
        }
    } else { // assume home built object
        for (rule in rules) {
            if (!rules.hasOwnProperty(rule)) continue;
            if (html_id) {
                this.upsertRule(this.updateSelector(rule, html_id), rules[rule]);
            } else {
                this.upsertRule(rule, rules[rule]);
            }
        }
    }
    return this; // enable chaining
}

PEAT_style.prototype.clearRules = function () {
    var i, index = this.rules.length - 1;
    for (i = index; i >= 0; i--) {
        this.CSSStyleSheet.deleteRule(i);
    }
    return this; // enable chaining
}

PEAT_style.prototype.removeRules = function (rules, entirelyBySelector) {
    if (!entirelyBySelector) {
        console.error('Currently rules cannot be removed in part');
        return;
    }
    var i, selector_text, index = this.rules.length - 1;
    for (i = index; i >= 0; i--) {
        selector_text = this.rules[i].selectorText;
        if (rules.hasOwnProperty(selector_text)) {
            this.CSSStyleSheet.deleteRule(i);
            //delete rules[selector_text]; // <- TODO check if this speeds up the matching, but might interfere with caching
        }
    }
    return this; // enable chaining
}

PEAT_style.prototype.convertRulesByUnit = function (unit, unit_to, multiplier, rules) {
    // returns all the rules that have this unit defined, as an object (named array) selectorText: cssText
    var converted_rules = {},
        i, len, current_style, css_index, css_colon_index, css_text, selector_text, css_value;
    if (unit.indexOf(';') === -1) unit += ';';
    if (!['vw;'].includes(unit)) {
        console.error('PEAT_style.convertRulesByUnit does not currently support ' + unit);
        return {};
    }
    if (!rules) rules = this.rules;
    for (i = 0, len = rules.length; i < len; ++i) {
        if (!rules.hasOwnProperty(i)) continue;
        current_style = rules[i];
        if ((css_index = (css_text = current_style.cssText).indexOf(unit)) > -1) {
            if ((selector_text = current_style.selectorText)) {
                css_colon_index = css_text.lastIndexOf(':', css_index);
                css_value = parseInt(css_text.substring(css_colon_index + 1, css_index));
                var index_from = Math.max(
                    css_text.lastIndexOf('{', css_colon_index),
                    css_text.lastIndexOf(';', css_colon_index)
                );
                var property_name = css_text.substring(index_from + 1, css_colon_index).trim();
                converted_rules[selector_text] = property_name + ':' + PEATCMS.cleanUpNumber(multiplier * css_value) + unit_to;
            } else {
                console.error(current_style.conditionText);
            }
        }
    }
    console.log(converted_rules);
    return converted_rules;
}

PEAT_style.prototype.convert = function (unit, lostpixels) {
    if (unit === 'vw;') {
        var ojee = selector_text.split('-width'), css_index, css_value, selector_text;
        for (css_index in ojee) {
            css_value = parseInt(ojee[css_index].replace(':', '')) + lostpixels;
            if (!isNaN(css_value)) ojee[css_index] = css_value + 'px'
        }
        selector_text = ojee.join('-width:');
    }
}

/* TODO refactor these into ponyfills... */
if (!Date.now) {
    Date.now = function () {
        return new Date().getTime();
    }
}

if (!Element.prototype.getAttributeNames) {
    Element.prototype.getAttributeNames = function () {
        var attributes = this.attributes;
        var length = attributes.length;
        var result = new Array(length);
        for (var i = 0; i < length; i++) {
            result[i] = attributes[i].name;
        }
        return result;
    };
}
if (![].includes) {
    // noinspection JSUnusedGlobalSymbols
    Array.prototype.includes = function (searchElement /*, fromIndex*/) {
        var O = Object(this);
        var len = parseInt(O.length) || 0;
        if (len === 0) {
            return false;
        }
        var n = parseInt(arguments[1]) || 0;
        var k;
        if (n >= 0) {
            k = n;
        } else {
            k = len + n;
            if (k < 0) {
                k = 0;
            }
        }
        var currentElement;
        while (k < len) {
            currentElement = O[k];
            if (searchElement === currentElement ||
                (searchElement !== searchElement && currentElement !== currentElement)) {
                return true;
            }
            k++;
        }
        return false;
    };
}
/**
 * Object.assign() polyfill for IE11
 * @see <https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object/assign>
 *     https://gist.github.com/jrrio/4dd2bec652dd642517a390172be27da2
 */
if (typeof Object.assign != "function") {
    Object.defineProperty(Object, "assign", {
        value: function assign(target, varArgs) {
            "use strict";
            if (target == null) {
                throw new TypeError("Cannot convert undefined or null to object");
            }
            var to = Object(target);
            for (var index = 1; index < arguments.length; index++) {
                var nextSource = arguments[index];
                if (nextSource != null) {
                    for (var nextKey in nextSource) {
                        if (Object.prototype.hasOwnProperty.call(nextSource, nextKey)) {
                            to[nextKey] = nextSource[nextKey];
                        }
                    }
                }
            }
            return to;
        },
        writable: true,
        configurable: true
    });
}

// custom event dispatching for IE11
// https://stackoverflow.com/questions/26596123/internet-explorer-9-10-11-event-constructor-doesnt-work
(function () {
    if (typeof window.CustomEvent === "function") return false; //If not IE

    CustomEvent = function (event, params) {
        params = params || {bubbles: false, cancelable: false, detail: undefined};
        var evt = document.createEvent('CustomEvent');
        evt.initCustomEvent(event, params.bubbles, params.cancelable, params.detail);
        return evt;
    }

    CustomEvent.prototype = window.Event.prototype;

    window.CustomEvent = CustomEvent;
})();

/**
 * ponyfills are static methods of PEATCMS :-)
 */
String.prototype.replaceAll = function (search, replace) {
    console.warn('String.prototype.replaceAll is deprecated, use PEATCMS.replace');
    return PEATCMS.replace(search, replace, this);
};
PEATCMS.replace = function (search, replace, str) {
    var index = str.indexOf(search),
        replace_length = replace.length,
        search_length = search.length;
    while (index !== -1) {
        str = str.substring(0, index) + replace + str.substring(index + search_length, str.length);
        index += replace_length;
        index = str.indexOf(search, index);
    }
    return str;
}

String.prototype.hashCode = function () {
    console.warn('String.prototype.hashCode is deprecated, use PEATCMS.numericHashFromString');
    return PEATCMS.numericHashFromString(this);
};
PEATCMS.numericHashFromString = function (str) {
    var hash = 0, i, chr, len = str.length;
    if (len === 0) return hash;
    for (i = 0; i < len; i++) {
        chr = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + chr;
        hash |= 0; // Convert to 32bit integer
    }
    return hash;
}

Number.prototype.cleanUp = function () {
    console.warn('Number.prototype.cleanUp is deprecated, use PEATCMS.cleanUpNumber');
    return PEATCMS.cleanUpNumber(this);
}
PEATCMS.cleanUpNumber = function (nr) {
    // function removes artefacts caused by decimal rounding error
    var n = nr.toString(), i = n.lastIndexOf('.'), d, index;
    if (i > -1) {
        d = n.substring(i);
        if ((index = d.indexOf('00000')) > -1) {
            d = d.substring(0, index);
        }
        if ((index = d.indexOf('99999')) > -1) {
            d = d.substring(0, index);
            // last number can never be 9 (cause 99999 are stripped), so safely add 1 for rounding all the 9's
            if (d === '.') { // round the int part up
                n = (parseInt(n) + 1).toString();
            } else { // round the last decimal up
                d = d.substring(0, d.length - 1) + (parseInt(d.substr(-1)) + 1);
            }
        }
        n = n.substring(0, i) + d;
    }
    return parseFloat(n);
};

PEATCMS.removeNode = function (node) {
    var parent_node;
    if (null === node) return;
    // there are edgeCases (e.g. with the PEATCMS_edit_button) where the parentNode === null
    // you do not have to remove the node then, since it’s not attached to the DOM anyway, it will be garbage collected
    if (null !== (parent_node = node.parentNode)) parent_node.removeChild(node);
}

function removeNode(node) {
    console.warn('removeNode() is deprecated, use PEATCMS.removeNode()');
    return PEATCMS.removeNode(node);
}

PEATCMS.opacityNode = function (node, opacity) {
    if (null === node) return;
    node.style.opacity = (Math.min(Math.abs(opacity), 1)).toString();
}

function opacityNode(node, opacity) {
    console.warn('opacityNode() is deprecated, use PEATCMS.opacityNode()');
    return PEATCMS.removeNode(node, opacity);
}

PEATCMS.isInt = function (value) {
    var x;
    if (isNaN(value)) {
        return false;
    }
    x = parseFloat(value);
    return (x | 0) === x;
}

function isInt(value) {
    console.warn('isInt() is deprecated, use PEATCMS.isInt()');
    return PEATCMS.isInt(value);
}

PEATCMS.cloneShallow = function (obj) {
    return Object.assign({}, obj);
}

function cloneShallow(obj) {
    console.warn('cloneShallow() is deprecated, use PEATCMS.cloneShallow()');
    return PEATCMS.cloneShallow(obj);
}

// https://stackoverflow.com/a/55292366
PEATCMS.trim = function (str, chars) {
    var start = 0, end;
    if (!str) return str;
    end = str.length;
    if (!chars) chars = ' ';
    while (start < end && chars.indexOf(str[start]) >= 0)
        ++start;
    while (end > start && chars.indexOf(str[end - 1]) >= 0)
        --end;
    return (start > 0 || end < str.length) ? str.substring(start, end) : str;
}

function trim(str, chars) {
    console.warn('trim() is deprecated, use PEATCMS.trim()');
    return PEATCMS.trim(str, chars);
}

PEATCMS.getFormData = function (form) {
    let elements = form.elements,
        element, value, i, len, obj = {}
    for (i = 0, len = elements.length; i < len; i++) {
        element = elements[i];
        value = element.value;
        if (PEATCMS.isInt(value)) {
            value = parseInt(value);
        } else if ('true' === value) {
            value = true;
        } else if ('false' === value) {
            value = false;
        }
        obj[element.name] = value;
    }
    return obj;
}

function getFormData(form) {
    console.warn('getFormData() is deprecated, use PEATCMS.getFormData()');
    return PEATCMS.getFormData(form);
}

// https://stackoverflow.com/a/11077016
PEATCMS.insertAtCaret = function (input_element, str_value) {
    var sel, start, end;
    // IE support
    if (document.selection) {
        input_element.focus();
        sel = document.selection.createRange();
        sel.text = str_value;
    }
    // MOZILLA and others
    else if (input_element.selectionStart || input_element.selectionStart == '0') {
        start = input_element.selectionStart;
        end = input_element.selectionEnd;
        input_element.value = input_element.value.substring(0, start)
            + str_value
            + input_element.value.substring(end, input_element.value.length);
        input_element.selectionStart = start + str_value.length;
        input_element.selectionEnd = start + str_value.length;
    } else {
        input_element.value += str_value;
    }
}

function insertAtCaret(input_element, str_value) {
    console.warn('insertAtCaret() is deprecated, use PEATCMS.insertAtCaret()');
    return PEATCMS.insertAtCaret(input_element, str_value);
}

/**
 *  On older iPads (at least iOS 8 + 9) the getBoundingClientRect() gets migrated all the way outside the
 *  viewport inconsistently while scrolling with touch, so we roll our own function
 */
PEATCMS.getBoundingClientTop = function (el) {
    var elementTop = 0, scrollTop = window.pageYOffset;
    // offsetParent: null for body, and in some browsers null for a fixed element, but than we have returned already
    while (el) {
        elementTop += el.offsetTop;
        if (scrollTop > 0
            && ('fixed' === (el.style.position.toLowerCase()
                || window.getComputedStyle(el).getPropertyValue('position').toLowerCase()))) {
            return elementTop;
        }
        el = el.offsetParent; // this is either null for body, or maybe a fixed element, but we returned early then
    }
    return elementTop - scrollTop;
}


/**
 * peatcms-slider https://cheewebdevelopment.com/boilerplate-vanilla-javascript-content-slider/
 */
document.addEventListener('peatcms.document_ready', function () {
    var slideshows = document.getElementsByClassName('peatcms-slideshow'),
        len, i;
    for (i = 0, len = slideshows.length; i < len; ++i) {
        peatcms_slideshow(slideshows[i]);
    }
});

function peatcms_slideshow(slideshow) {
    if (!(slideshow instanceof Element)) {
        console.error('Supply dom element that is wrapper of slideshow', slideshow);
        return;
    }
    clearInterval(slideshow.peatcms_interval);

    var slides = slideshow.getElementsByClassName('peatcms-slide-entry'),
        slideCount = slides.length,
        show_time = parseInt(slideshow.getAttribute('data-interval') || 10000),
        currentSlide = 0,
        slideHeight = null,
        i;

    if (typeof CMS_admin === 'undefined') {
        for (i = slides.length - 1; i >= 0; --i) {
            if (slides[i].hasAttribute('data-online') && slides[i].getAttribute('data-online') === 'false') {
                PEATCMS.removeNode(slides[i]);
                slideCount--;
            }
        }
    }
    if (slideCount <= 0) {
        PEATCMS.removeNode(slideshow);
        return;
    }

    var moveToSlide = function (n) { // set up our slide navigation functionality
        slides[currentSlide].classList.remove('active');
        currentSlide = (n + slideCount) % slideCount;
        slides[currentSlide].classList.add('active');
        slideHeight = slides[currentSlide].clientHeight;
        slideshow.style.height = slideHeight + 'px';
    }

    var nextSlide = function (e) {
        moveToSlide(currentSlide + 1);
        if (e && e.keyCode === 39) {
            moveToSlide(currentSlide + 1);
        }
    }

    var prevSlide = function (e) {
        moveToSlide(currentSlide + -1);
        if (e && e.keyCode === 37) {
            moveToSlide(currentSlide + -1);
        }
    }

    var slideHandlers = {
        nextSlide: function (element) {
            if (!slideshow.querySelector(element)) return;
            slideshow.querySelector(element).addEventListener('click', function () {
                clearInterval(slideshow.peatcms_interval);
                nextSlide();
            });
            //document.body.addEventListener('keydown', nextSlide, false);
        },
        prevSlide: function (element) {
            if (!slideshow.querySelector(element)) return;
            slideshow.querySelector(element).addEventListener('click', function () {
                clearInterval(slideshow.peatcms_interval);
                prevSlide();
            });
            //document.body.addEventListener('keydown', prevSlide, false);
        }
    }

    slideHandlers.nextSlide('.button.next');
    slideHandlers.prevSlide('.button.previous');

    slides[0].classList.add('active'); //on load, activate the first slide

    PEAT.swipifyDOMElement(slideshow, nextSlide, prevSlide);

    // autoplay
    slideshow.peatcms_interval = setInterval(nextSlide, show_time);
}

/**
 * startup peatcms object
 */
function peatcms_start() {
    if (VERBOSE) console.log('Starting peatcms...');
    // the PEAT object holds the website and its representations and everything
    // it also creates the NAV object that takes care of ajax and navigation
    if (typeof PEAT === 'undefined') {
        PEAT = new PEATCMS();
        PEAT.startUp();
    } else {
        if (VERBOSE) console.log('... already started!');
    }
}

if (document.readyState !== 'loading') {
    peatcms_start();
} else {
    document.addEventListener('DOMContentLoaded', function () {
        peatcms_start();
    });
}
