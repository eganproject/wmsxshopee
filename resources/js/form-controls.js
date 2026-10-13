import TomSelect from 'tom-select';
import flatpickr from 'flatpickr';
import { Indonesian } from 'flatpickr/dist/l10n/id.js';

/*
 * Kontrol form yang diperkaya:
 * - Setiap <select class="form-input"> otomatis memakai Tom Select (bisa dicari, multi, AJAX).
 * - Setiap <input class="form-input"> bertipe date / datetime-local / time / month memakai Flatpickr.
 * Tambahkan atribut `data-native` untuk tetap memakai kontrol bawaan browser.
 *
 * Opsi lewat atribut data:
 *   data-remote="/url"        cari opsi lewat AJAX (?q=...), respons: [{ value, text }]
 *   data-placeholder="..."    teks placeholder (default: label opsi kosong)
 *   data-create               boleh menambah opsi baru dengan mengetik
 *
 * Select di dalam x-for Alpine yang opsinya dinamis memakai directive x-tom-select (lihat di bawah).
 */

flatpickr.localize(Indonesian);

const SELECT_SELECTOR = 'select.form-input:not([data-native]):not([x-tom-select])';
const DATE_SELECTOR = 'input.form-input:is([type="date"], [type="datetime-local"], [type="time"], [type="month"]):not([data-native])';

function emptyOptionText(select) {
    return select.querySelector('option[value=""]')?.textContent.trim() || 'Pilih...';
}

function baseSelectSettings(select) {
    const multiple = select.multiple;
    const plugins = {};

    if (multiple) {
        plugins.remove_button = { title: 'Hapus' };
    }
    if (! multiple && select.querySelector('option[value=""]')) {
        plugins.clear_button = { title: 'Kosongkan' };
    }

    return {
        plugins,
        allowEmptyOption: false,
        placeholder: select.dataset.placeholder ?? emptyOptionText(select),
        hidePlaceholder: multiple,
        maxOptions: 200,
        create: select.hasAttribute('data-create'),
        // Dropdown ditempel ke body agar tidak terpotong card / tabel yang overflow.
        dropdownParent: 'body',
        controlInput: '<input type="text" autocomplete="off" size="1">',
        render: {
            no_results: () => '<div class="no-results">Tidak ada hasil</div>',
            loading: () => '<div class="spinner"></div>',
            option_create: (data, escape) => `<div class="create">Tambah <strong>${escape(data.input)}</strong>&hellip;</div>`,
        },
        onInitialize() {
            // Wrapper mewarisi kelas <select> (lebar, dll.), tapi kotaknya digambar oleh .ts-control.
            this.wrapper.classList.remove('form-input', 'form-input-error');
            mirrorState(this);
        },
    };
}

function remoteSettings(url) {
    return {
        valueField: 'value',
        labelField: 'text',
        searchField: [],
        loadThrottle: 300,
        shouldLoad: (query) => query.length >= 1,
        load(query, callback) {
            window.axios
                .get(url, { params: { q: query } })
                .then(({ data }) => callback(data))
                .catch(() => callback());
        },
        // Jangan filter ulang di sisi klien: server sudah mencocokkan.
        score: () => () => 1,
    };
}

/* Kelas error & disabled pada <select> asli ikut ditampilkan di wrapper Tom Select. */
function mirrorState(ts) {
    const sync = () => {
        ts.wrapper.classList.toggle('is-invalid', ts.input.classList.contains('form-input-error'));
    };
    sync();
    new MutationObserver(sync).observe(ts.input, { attributes: true, attributeFilter: ['class'] });
}

export function enhanceSelect(select, extra = {}) {
    if (select.tomselect) {
        return select.tomselect;
    }

    const settings = { ...baseSelectSettings(select), ...extra };

    if (select.dataset.remote) {
        Object.assign(settings, remoteSettings(select.dataset.remote));
    }

    return new TomSelect(select, settings);
}

function dateSettings(input) {
    const type = input.getAttribute('type');
    const formats = {
        date: { dateFormat: 'Y-m-d', altFormat: 'j M Y' },
        'datetime-local': { dateFormat: 'Y-m-d\\TH:i', altFormat: 'j M Y H:i', enableTime: true },
        time: { dateFormat: 'H:i', altFormat: 'H:i', enableTime: true, noCalendar: true },
        month: { dateFormat: 'Y-m', altFormat: 'F Y' },
    };

    return {
        ...formats[type],
        altInput: true,
        altInputClass: `${input.className} fp-alt fp-${type}`,
        allowInput: true,
        time_24hr: true,
        minDate: input.getAttribute('min') || null,
        maxDate: input.getAttribute('max') || null,
        // Pilihan bulan/tahun di header memakai select bawaan, ikut tema.
        monthSelectorType: 'static',
        onReady(selectedDates, dateStr, instance) {
            instance.calendarContainer.classList.toggle('only-time', type === 'time');
            if (instance.altInput) {
                instance.altInput.placeholder = input.getAttribute('placeholder') || (type === 'time' ? 'JJ:MM' : 'Pilih tanggal');
            }
        },
    };
}

export function enhanceDate(input, extra = {}) {
    if (input._flatpickr) {
        return input._flatpickr;
    }

    // Flatpickr butuh input teks: tipe asli dipakai hanya untuk menentukan format.
    const settings = { ...dateSettings(input), ...extra };
    input.setAttribute('type', 'text');

    return flatpickr(input, settings);
}

export function enhance(root = document) {
    root.querySelectorAll(SELECT_SELECTOR).forEach((select) => enhanceSelect(select));
    root.querySelectorAll(DATE_SELECTOR).forEach((input) => enhanceDate(input));
}

/*
 * Untuk select yang dikendalikan Alpine (misalnya baris dalam x-for):
 *
 *   <select x-model="item.location_id"
 *           x-tom-select="{ options: lokasi.map(l => ({ value: l.id, text: l.code })), value: item.location_id }">
 *
 * Opsi & nilai dibaca ulang setiap kali datanya berubah; pilihan pengguna dikirim
 * balik ke x-model lewat event change pada <select> asli.
 */
export function registerAlpine(Alpine) {
    Alpine.directive('tom-select', (el, { expression }, { evaluateLater, effect, cleanup }) => {
        const read = evaluateLater(expression);
        const ts = enhanceSelect(el, { placeholder: el.dataset.placeholder ?? 'Pilih...' });

        effect(() => {
            read(({ options = [], value = '' }) => {
                const normalized = options.map((option) => ({ value: String(option.value), text: String(option.text) }));
                const current = value === null || value === undefined ? '' : String(value);

                ts.clear(true);
                ts.clearOptions();
                ts.addOptions(normalized);
                ts.refreshOptions(false);

                if (current !== '' && normalized.some((option) => option.value === current)) {
                    ts.setValue(current, true);
                }
            });
        });

        cleanup(() => ts.destroy());
    });
}
