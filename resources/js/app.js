import Alpine from 'alpinejs';
import { registerDemoGuide } from './demo-guide';

window.Alpine = Alpine;

registerDemoGuide(Alpine);

Alpine.start();
