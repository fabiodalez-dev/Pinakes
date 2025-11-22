/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./css/input.css":
/*!***********************!*\
  !*** ./css/input.css ***!
  \***********************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*********************!*\
  !*** ./js/index.js ***!
  \*********************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _css_input_css__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ../css/input.css */ "./css/input.css");


// Dark mode toggle persisted in localStorage
var STORAGE_KEY = 'darkMode';
function applyDarkModeClass(value) {
  var root = document.documentElement;
  if (value) root.classList.add('dark');else root.classList.remove('dark');
}
function initDarkMode() {
  try {
    var saved = localStorage.getItem(STORAGE_KEY);
    var isDark = saved ? JSON.parse(saved) : false;
    applyDarkModeClass(isDark);
    window.toggleDarkMode = function () {
      var next = !document.documentElement.classList.contains('dark');
      applyDarkModeClass(next);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    };
  } catch (e) {}
}
document.addEventListener('DOMContentLoaded', initDarkMode);
})();

/******/ })()
;
//# sourceMappingURL=main.bundle.js.map