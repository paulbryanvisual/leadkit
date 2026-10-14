// Zapier → Code by Zapier → Run JavaScript. Input Data: raw = Give Lively's "Utm Source".
// Splits LeadKit's packed source back into its fields (either pair separator,
// "__" or "-"); unknown keys are ignored. Paste the whole file into the step.
const raw = String( inputData.raw || '' ).trim();
const out = { medium: '', source: '', campaign: '', content: '', term: '', visitorid: '' };
raw.split( '__' ).forEach( ( chunk ) => {
	chunk.split( /-(?=(?:medium|source|campaign|content|term|visitorid)\.)/ ).forEach( ( pair ) => {
		const dot = pair.indexOf( '.' );
		if ( dot < 1 ) return;
		const key = pair.slice( 0, dot );
		if ( key in out && ! out[ key ] ) out[ key ] = pair.slice( dot + 1 );
	} );
} );
return out;
