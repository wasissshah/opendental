<?php
// viewer.php?loc=ID — Open Dental data viewer for one location (admins + assigned staff).
require __DIR__ . '/lib/bootstrap.php';
[$user, $locId] = require_location_access();
$loc = location_get($locId);
$odReady = integration_get($locId, 'open_dental')['exists'];

render_header($loc['name'] . ' · Data', $user['role'] === 'admin' ? 'locations' : 'mine');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.1.2/css/buttons.dataTables.min.css">
<style>
  .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
  .tab { padding: 7px 14px; border: 1px solid #cfd8dc; background: #fff; border-radius: 20px; cursor: pointer; font-size: 13px; }
  .tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
  .filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
  .filters label { margin: 0; }
  .filters input, .filters select { min-width: 150px; }
  #status { font-size: 13px; color: var(--muted); }
  #status.error { color: var(--bad); }
  table.dataTable td { font-size: 13px; white-space: nowrap; }
  table.dataTable tbody tr.row-cancelled > td { background: #fdecea !important; color: #9b2c2c; }
  table.dataTable tbody tr.row-complete > td { background: #eef8f0 !important; }
  .legend { font-size: 12px; color: var(--muted); margin-top: 8px; display: flex; gap: 14px; }
  .legend span::before { content: ''; display: inline-block; width: 10px; height: 10px; margin-right: 5px; border-radius: 2px; vertical-align: middle; }
  .legend .l-cancel::before { background: #f5b7b1; }
  .legend .l-complete::before { background: #b9e2c2; }
  button.link { background: none; border: 0; color: var(--brand); cursor: pointer; font-size: 13px; padding: 8px 4px; }
</style>

<div class="head">
  <div>
    <a href="<?= $user['role'] === 'admin' ? 'location.php?loc=' . $locId : 'my_locations.php' ?>">← <?= $user['role'] === 'admin' ? e($loc['name']) : 'My locations' ?></a>
    <h1 style="margin-top:6px"><?= e($loc['name']) ?></h1>
  </div>
  <?php if ($user['role'] === 'admin'): ?>
    <a class="btn" href="sync.php?loc=<?= $locId ?>">Sync to GHL</a>
  <?php endif; ?>
</div>

<?php if (!$odReady): ?>
  <div class="card"><h2>Open Dental isn't set up yet</h2><p>An admin needs to add the Open Dental keys on this location's Configuration Page.</p></div>
<?php else: ?>
<div class="card">
  <div class="tabs" id="tabs"></div>
  <div class="filters" id="filters"></div>
  <div style="margin-top:14px; display:flex; align-items:center; gap:8px;">
    <button class="btn" id="loadBtn">Load</button>
    <button class="link" id="clearBtn">Clear filters</button>
    <span id="status"></span>
  </div>
  <div class="hint" id="hint"></div>
  <div class="legend"><span class="l-cancel">Cancelled</span><span class="l-complete">Completed</span></div>
</div>

<div class="card" style="overflow-x:auto">
  <table id="dataTable" class="display" style="width:100%"></table>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.colVis.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script>
    // ---------- What can be fetched, and the filters for each ----------
    const APT_STATUS = ['', 'Scheduled', 'Complete', 'UnschedList', 'ASAP', 'Broken', 'Planned', 'PtNote', 'PtNoteCompleted'];
    const PAT_STATUS = ['', 'Patient', 'NonPatient', 'Inactive', 'Archived', 'Deceased', 'Prospective'];
    const GENDER     = ['', 'Male', 'Female', 'Unknown', 'Other'];
    const CANCEL_FILTER = [
      { value: '',            label: 'All appointments' },
      { value: 'cancelled',   label: 'Cancelled (all)' },
      { value: 'broken',      label: 'Cancelled – still on schedule (Broken)' },
      { value: 'unscheduled', label: 'Cancelled – moved to Unscheduled List' },
      { value: 'active',      label: 'Not cancelled (Scheduled + Complete)' },
      { value: 'upcoming',    label: 'Scheduled only' }
    ];

    // Readable cancellation status for each appointment
    function cancelStatus(apt) {
      switch (apt.AptStatus) {
        case 'Broken':      return 'Cancelled';
        case 'UnschedList': return 'Cancelled – unscheduled';
        case 'Scheduled':   return 'Scheduled';
        case 'Complete':    return 'Completed';
        case 'Planned':     return 'Planned';
        default:            return apt.AptStatus || '';
      }
    }

    const RESOURCES = {
      appointments: {
        label: 'Appointments',
        hint: 'Leave dates empty to get all appointments (can be slow). Cancellation filter overrides the Status filter. In Open Dental, cancelled appointments are marked "Broken".',
        filters: [
          { name: 'dateStart', label: 'From', type: 'date' },
          { name: 'dateEnd',   label: 'To',   type: 'date' },
          { name: 'date',      label: 'Single day', type: 'date' },
          { name: 'cancelFilter', label: 'Cancellation', type: 'select', options: CANCEL_FILTER },
          { name: 'AptStatus', label: 'Status', type: 'select', options: APT_STATUS },
          { name: 'PatNum',    label: 'Patient #', type: 'number' },
          { name: 'Op',        label: 'Operatory #', type: 'number' },
          { name: 'ClinicNum', label: 'Clinic #', type: 'number' },
          { name: 'AppointmentTypeNum', label: 'Appt Type #', type: 'number' }
        ],
        show: ['AptNum', 'PatNum', 'AptDateTime', 'CancelStatus', 'AptStatus', 'confirmed', 'unschedStatus', 'ProcDescript', 'Op', 'provAbbr', 'IsNewPatient', 'Priority', 'DateTStamp', 'Note']
      },
      slots: {
        label: 'Open Slots',
        hint: 'Dates must be today or later. Default is the next 14 days. Usually pick a Provider # and Operatory #.',
        filters: [
          { name: 'dateStart', label: 'From', type: 'date' },
          { name: 'dateEnd',   label: 'To',   type: 'date' },
          { name: 'date',      label: 'Single day', type: 'date' },
          { name: 'lengthMinutes', label: 'Min length (minutes)', type: 'number' },
          { name: 'ProvNum',   label: 'Provider #', type: 'number' },
          { name: 'OpNum',     label: 'Operatory #', type: 'number' }
        ],
        show: null
      },
      asap: {
        label: 'ASAP List',
        hint: 'Clinic # is required only if the practice uses clinics.',
        filters: [
          { name: 'ClinicNum', label: 'Clinic #', type: 'number' },
          { name: 'ProvNum',   label: 'Provider #', type: 'number' }
        ],
        show: ['AptNum', 'PatNum', 'AptDateTime', 'AptStatus', 'ProcDescript', 'Op', 'provAbbr', 'Priority', 'Note']
      },
      patients: {
        label: 'Patients',
        hint: 'Name filters match partially and ignore case.',
        filters: [
          { name: 'LName',     label: 'Last name', type: 'text' },
          { name: 'FName',     label: 'First name', type: 'text' },
          { name: 'PatStatus', label: 'Status', type: 'select', options: PAT_STATUS },
          { name: 'Gender',    label: 'Gender', type: 'select', options: GENDER },
          { name: 'Birthdate', label: 'Birthdate', type: 'date' },
          { name: 'PriProv',   label: 'Primary provider #', type: 'number' },
          { name: 'ClinicNum', label: 'Clinic #', type: 'number' }
        ],
        show: ['PatNum', 'LName', 'FName', 'PatStatus', 'Gender', 'Birthdate', 'WirelessPhone', 'HmPhone', 'Email', 'City', 'priProvAbbr', 'EstBalance', 'DateFirstVisit']
      },
      providers: {
        label: 'Providers',
        hint: 'Use these numbers in the Provider # filters.',
        filters: [ { name: 'ClinicNum', label: 'Clinic #', type: 'number' } ],
        show: null
      },
      operatories: {
        label: 'Operatories',
        hint: 'Use these numbers in the Operatory # filters.',
        filters: [ { name: 'ClinicNum', label: 'Clinic #', type: 'number' } ],
        show: null
      },
      clinics: {
        label: 'Clinics',
        hint: 'Empty if the practice does not use clinics.',
        filters: [],
        show: null
      },
      appointmenttypes: {
        label: 'Appointment Types',
        hint: '',
        filters: [],
        show: null
      }
    };

    const LOC_ID = <?= (int)$locId ?>;
    let current = 'appointments';
    let table = null;

    // ---------- Build tabs and filter inputs ----------
    function renderTabs() {
      const $tabs = $('#tabs').empty();
      Object.entries(RESOURCES).forEach(([key, r]) => {
        $('<button class="tab">')
          .text(r.label)
          .toggleClass('active', key === current)
          .on('click', () => { current = key; renderTabs(); renderFilters(); loadData(); })
          .appendTo($tabs);
      });
    }

    function renderFilters() {
      const r = RESOURCES[current];
      const $f = $('#filters').empty();

      if (!r.filters.length) {
        $f.append('<span style="font-size:13px;color:#777">No filters for this data.</span>');
      }

      r.filters.forEach(f => {
        const $label = $('<label>').text(f.label);
        let $input;
        if (f.type === 'select') {
          $input = $('<select>');
          f.options.forEach(o => {
            const val = typeof o === 'object' ? o.value : o;
            const txt = typeof o === 'object' ? o.label : (o || 'All');
            $input.append($('<option>').val(val).text(txt));
          });
        } else {
          $input = $('<input>').attr('type', f.type);
        }
        $input.attr('data-name', f.name);
        $label.append($input);
        $f.append($label);
      });

      $('#hint').text(r.hint || '');
    }

    function collectFilters() {
      const params = new URLSearchParams({ resource: current });
      $('#filters [data-name]').each(function () {
        const v = $(this).val();
        if (v !== '' && v !== null) params.append($(this).data('name'), v);
      });
      return params.toString();
    }

    // ---------- Formatting ----------
    function formatCell(value) {
      if (value === null || value === undefined) return '';
      if (typeof value === 'string' && value.startsWith('0001-01-01')) return '';
      if (typeof value === 'object') return JSON.stringify(value);
      return value;
    }

    // ---------- Load data and (re)build the table ----------
    function loadData() {
      const r = RESOURCES[current];
      $('#status').removeClass('error').text('Loading ' + r.label.toLowerCase() + '…');
      $('#loadBtn').prop('disabled', true);

      $.getJSON('api.php?loc=' + LOC_ID + '&' + collectFilters())
        .done(res => buildTable(res.data || []))
        .fail(xhr => {
          let msg = 'Request failed';
          try { msg = JSON.parse(xhr.responseText).error; } catch (e) {}
          $('#status').addClass('error').text(msg);
          buildTable([]);
        })
        .always(() => $('#loadBtn').prop('disabled', false));
    }

    function buildTable(rows) {
      const r = RESOURCES[current];

      if (table) {
        table.destroy();
        $('#dataTable').empty();
        table = null;
      }

      // Add a readable cancellation column to appointment data
      const isAppt = current === 'appointments' || current === 'asap';
      if (isAppt) {
        rows.forEach(a => { a.CancelStatus = cancelStatus(a); });
        // Newest first
        rows.sort((a, b) => (b.AptDateTime || '').localeCompare(a.AptDateTime || ''));
      }

      // Columns come from the data itself, so every field is available
      const keys = rows.length ? Object.keys(rows[0]) : (r.show || ['No data']);
      const columns = keys.map(k => ({
        data: k,
        title: k,
        defaultContent: '',
        visible: !r.show || r.show.includes(k),
        render: (d, type) => type === 'display' ? $('<div>').text(formatCell(d)).html() : formatCell(d)
      }));

      table = new DataTable('#dataTable', {
        data: rows,
        columns: columns,
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100, 500],
        deferRender: true,
        order: [],
        createdRow: function (row, data) {
          if (data.AptStatus === 'Broken' || data.AptStatus === 'UnschedList') {
            $(row).addClass('row-cancelled');
          } else if (data.AptStatus === 'Complete') {
            $(row).addClass('row-complete');
          }
        },
        layout: {
          topStart: ['pageLength', {
            buttons: [
              { extend: 'colvis', text: 'Columns' },
              { extend: 'csv',   title: 'opendental-' + current, exportOptions: { columns: ':visible' } },
              { extend: 'excel', title: 'opendental-' + current, exportOptions: { columns: ':visible' } }
            ]
          }]
        }
      });

      if (!$('#status').hasClass('error')) {
        $('#status').text(rows.length + ' ' + r.label.toLowerCase() + ' loaded');
      }
    }

    // ---------- Events ----------
    $('#loadBtn').on('click', loadData);
    $('#clearBtn').on('click', () => $('#filters [data-name]').val(''));
    $('#filters').on('keydown', 'input', e => { if (e.key === 'Enter') loadData(); });

    $(function () {
      renderTabs();
      renderFilters();
      // Start with today to 14 days ahead so the first load is quick
      const d = n => { const x = new Date(); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
      $('[data-name=dateStart]').val(d(0));
      $('[data-name=dateEnd]').val(d(14));
      loadData();
    });
  </script>
<?php endif; ?>
<?php render_footer();
