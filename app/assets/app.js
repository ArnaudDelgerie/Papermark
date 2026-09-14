import { config } from '@hotwired/turbo';
import './stimulus_bootstrap.js';

// Turbo Frames only: no Drive navigation. Frames stay enabled by default,
// unlike data-turbo="false" on <body>, which frames would inherit.
config.drive.enabled = false;
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';
