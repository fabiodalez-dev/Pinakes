// Vendor libraries bundle for modern library management system
import 'bootstrap/dist/css/bootstrap.min.css';
import '../css/bootstrap-overrides.css'; // Override Bootstrap primary color to gray-900
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import { Italian } from 'flatpickr/dist/l10n/it.js';
import { english } from 'flatpickr/dist/l10n/default.js';
import 'sweetalert2/dist/sweetalert2.min.css';

// Uppy - file upload library (all self-hosted, no CDN)
import * as UppyCore from '@uppy/core';
import '@uppy/core/dist/style.min.css';
import * as Dashboard from '@uppy/dashboard';
import '@uppy/dashboard/dist/style.min.css';
import * as DragDrop from '@uppy/drag-drop';
import '@uppy/drag-drop/dist/style.min.css';
import * as ProgressBar from '@uppy/progress-bar';
import '@uppy/progress-bar/dist/style.min.css';
import * as XHRUpload from '@uppy/xhr-upload';

import Choices from 'choices.js';
import 'choices.js/public/assets/styles/choices.min.css';
import Swal from 'sweetalert2';
import '@fortawesome/fontawesome-free/css/all.min.css';

// Chart.js - charting library (self-hosted, no CDN)
import Chart from 'chart.js/auto';

// DataTables and extensions
import DataTable from 'datatables.net';
import 'datatables.net-dt/css/dataTables.dataTables.min.css';
import 'datatables.net-responsive';
import 'datatables.net-responsive-dt';
import 'datatables.net-responsive-dt/css/responsive.dataTables.min.css';
import 'datatables.net-buttons';
import 'datatables.net-buttons-dt';
import 'datatables.net-buttons/js/buttons.html5.mjs';
import 'datatables.net-buttons/js/buttons.print.mjs';
import 'datatables.net-buttons-dt/css/buttons.dataTables.min.css';
import 'datatables.net-select';
import 'datatables.net-select-dt';
import 'datatables.net-select-dt/css/select.dataTables.min.css';
import 'datatables.net-searchpanes';
import 'datatables.net-searchpanes-dt';
import 'datatables.net-searchpanes-dt/css/searchPanes.dataTables.min.css';
import 'datatables.net-searchbuilder';
import 'datatables.net-searchbuilder-dt';
import 'datatables.net-searchbuilder-dt/css/searchBuilder.dataTables.min.css';

// DataTables export dependencies
import JSZip from 'jszip';
import pdfMake from 'pdfmake/build/pdfmake';
import pdfFonts from 'pdfmake/build/vfs_fonts';
// pdfmake v0.2.x exports the vfs on `vfs`, not `pdfMake.vfs`
pdfMake.vfs = (pdfFonts && (pdfFonts.vfs || (pdfFonts.pdfMake && pdfFonts.pdfMake.vfs))) || pdfMake.vfs;

// Make libraries available globally - use default exports
window.Uppy = UppyCore.default || UppyCore;
window.UppyCore = UppyCore.default || UppyCore;
window.UppyDashboard = Dashboard.default || Dashboard;
window.UppyDragDrop = DragDrop.default || DragDrop;
window.UppyProgressBar = ProgressBar.default || ProgressBar;
window.UppyXHRUpload = XHRUpload.default || XHRUpload;
window.Choices = Choices;
window.Swal = Swal;
window.Chart = Chart;

// Flatpickr with localization support
window.flatpickr = flatpickr;
window.flatpickrLocales = {
  it: Italian,
  en: english
};
flatpickr.localize(Italian); // Set Italian as default locale

// DataTables global setup
window.DataTable = DataTable;
window.$ = window.jQuery = require('jquery');
window.JSZip = JSZip;
window.pdfMake = pdfMake;

// Add jsPDF for PDF generation
window.jspdf = require('jspdf');

// Verify all libraries loaded (silent - logs removed for production)
// Uncomment for debugging:
// console.log('Vendor libraries loaded:', {
//     Uppy: !!window.Uppy,
//     UppyCore: !!window.UppyCore,
//     UppyDashboard: !!window.UppyDashboard,
//     DragDrop: !!window.UppyDragDrop,
//     ProgressBar: !!window.UppyProgressBar,
//     XHRUpload: !!window.UppyXHRUpload,
//     Choices: !!window.Choices,
//     Swal: !!window.Swal,
//     DataTable: !!window.DataTable,
//     jQuery: !!window.$,
//     JSZip: !!window.JSZip,
//     pdfMake: !!window.pdfMake
// });

// Inline Italian translations for DataTables to avoid extra network requests
window.DT_LANG_IT = {
  decimal: ",",
  thousands: ".",
  emptyTable: "Nessun dato disponibile nella tabella",
  info: "Visualizzazione da _START_ a _END_ di _TOTAL_ elementi",
  infoEmpty: "Nessun elemento disponibile",
  infoFiltered: "(filtrati da _MAX_ elementi totali)",
  lengthMenu: "Mostra _MENU_ elementi",
  loadingRecords: "Caricamento...",
  processing: "Elaborazione...",
  search: "Cerca:",
  zeroRecords: "Nessuna corrispondenza trovata",
  paginate: { first: "Prima", last: "Ultima", next: "Successiva", previous: "Precedente" },
  aria: {
    sortAscending: ": attiva per ordinare la colonna in ordine crescente",
    sortDescending: ": attiva per ordinare la colonna in ordine decrescente"
  },
  buttons: { copy: "Copia", colvis: "Visibilità colonne", excel: "Excel", pdf: "PDF", print: "Stampa", collection: "Collezione" },
  select: { rows: { _: "%d righe selezionate", 1: "1 riga selezionata" } }
};

// English translations for DataTables
window.DT_LANG_EN = {
  decimal: ".",
  thousands: ",",
  emptyTable: "No data available in table",
  info: "Showing _START_ to _END_ of _TOTAL_ entries",
  infoEmpty: "No entries available",
  infoFiltered: "(filtered from _MAX_ total entries)",
  lengthMenu: "Show _MENU_ entries",
  loadingRecords: "Loading...",
  processing: "Processing...",
  search: "Search:",
  zeroRecords: "No matching records found",
  paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" },
  aria: {
    sortAscending: ": activate to sort column ascending",
    sortDescending: ": activate to sort column descending"
  },
  buttons: { copy: "Copy", colvis: "Column visibility", excel: "Excel", pdf: "PDF", print: "Print", collection: "Collection" },
  select: { rows: { _: "%d rows selected", 1: "1 row selected" } }
};
