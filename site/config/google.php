<?php
/**
 * Google sign-in.
 *
 * The Web OAuth client ID from Google Cloud Console → APIs & Services →
 * Credentials. It is a public identifier, not a secret — it ships in every page
 * with the Google button. The app asks Google for tokens addressed to this same
 * ID (serverClientId), so one value checks website and app sign-ins alike.
 *
 * Empty = Google sign-in is off: the button hides and the API refuses.
 */
const GOOGLE_WEB_CLIENT_ID = '794246915972-lkonh5mthsaaqu9pa4ljmok6l6h5ghsu.apps.googleusercontent.com';
