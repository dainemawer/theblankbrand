import '../../css/frontend/style.css';

import { initNavigation, initMinicart, initAccountMenu, initMobileNav } from './components/navigation';
import { initScrollableTables } from './components/scrollable-tables';
import { initProductTableModal } from './components/product-table-modal';
import { initQuantityStepper } from './components/quantity-stepper';

document.addEventListener( 'DOMContentLoaded', () => {
	initNavigation();
	initMinicart();
	initAccountMenu();
	initMobileNav();
	initScrollableTables();
	initProductTableModal();
	initQuantityStepper();
} );
