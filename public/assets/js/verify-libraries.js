/**
 * Library Verification Script
 * Verifies that all required libraries are loaded and available
 */

(function() {
  'use strict';

  const libraries = {
    'Flatpickr': typeof window.flatpickr === 'function',
    'SweetAlert2': typeof window.Swal === 'function',
    'DataTable': typeof window.DataTable === 'function',
    'Choices': typeof window.Choices === 'function',
    'jQuery': typeof window.$ === 'function',
    'Uppy': typeof window.Uppy === 'function',
    'UppyCore': typeof window.UppyCore !== 'undefined',
    'UppyDashboard': typeof window.UppyDashboard !== 'undefined',
    'UppyDragDrop': typeof window.UppyDragDrop !== 'undefined',
    'UppyProgressBar': typeof window.UppyProgressBar !== 'undefined',
    'UppyXHRUpload': typeof window.UppyXHRUpload !== 'undefined',
    'JSZip': typeof window.JSZip === 'function',
    'pdfMake': typeof window.pdfMake !== 'undefined'
  };

  const functions = {
    'initFlatpickr': typeof window.initFlatpickr === 'function'
  };

  console.group('📦 Library Verification Report');
  console.log('Generated:', new Date().toLocaleString('it-IT'));

  console.group('📚 Libraries Status');
  let allLibrariesLoaded = true;
  Object.entries(libraries).forEach(([name, loaded]) => {
    const status = loaded ? '✅' : '❌';
    console.log(`${status} ${name}:`, loaded ? 'Loaded' : 'NOT FOUND');
    if (!loaded) allLibrariesLoaded = false;
  });
  console.groupEnd();

  console.group('⚙️ Custom Functions Status');
  let allFunctionsLoaded = true;
  Object.entries(functions).forEach(([name, loaded]) => {
    const status = loaded ? '✅' : '❌';
    console.log(`${status} ${name}:`, loaded ? 'Available' : 'NOT FOUND');
    if (!loaded) allFunctionsLoaded = false;
  });
  console.groupEnd();

  // Flatpickr specific checks
  if (typeof window.flatpickr === 'function') {
    console.group('🗓️ Flatpickr Details');
    console.log('✅ Constructor available');

    // Check localization
    const testDate = document.createElement('input');
    testDate.type = 'text';
    testDate.style.display = 'none';
    document.body.appendChild(testDate);

    try {
      const fp = window.flatpickr(testDate, {
        dateFormat: 'Y-m-d'
      });

      console.log('✅ Can create instance');
      console.log('Locale:', fp.l10n);
      console.log('First day of week:', fp.l10n.firstDayOfWeek || 0);

      fp.destroy();
      document.body.removeChild(testDate);
    } catch (e) {
      console.error('❌ Error creating instance:', e.message);
    }
    console.groupEnd();
  }

  // Summary
  console.group('📊 Summary');
  if (allLibrariesLoaded && allFunctionsLoaded) {
    console.log('✅ All checks passed!');
  } else {
    console.warn('⚠️ Some checks failed. Review the report above.');
  }
  console.groupEnd();

  console.groupEnd();

  // Store results globally
  window.libraryVerification = {
    timestamp: new Date().toISOString(),
    libraries: libraries,
    functions: functions,
    allPassed: allLibrariesLoaded && allFunctionsLoaded
  };

})();
