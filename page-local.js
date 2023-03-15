/**
 * Function that delays until all assets, including JavaScript have
 * run by checking the page size and waiting for it to stabilise
 * From: From https://stackoverflow.com/questions/52497252/puppeteer-wait-until-page-is-completely-loaded
 *  
 * @param page 
 * @param timeout 
 */
const waitTillHTMLRendered = async (page, timeout = 30000) => {
	const checkDurationMsecs = 1000;
	const maxChecks = timeout / checkDurationMsecs;
	let lastHTMLSize = 0;
	let checkCounts = 1;
	let countStableSizeIterations = 0;
	const minStableSizeIterations = 3;

	while (checkCounts++ <= maxChecks) {
		let html = await page.content();
		let currentHTMLSize = html.length;

		let bodyHTMLSize = await page.evaluate(() => document.body.innerHTML.length);

		//console.log('last: ', lastHTMLSize, ' <> curr: ', currentHTMLSize, " body html size: ", bodyHTMLSize);

		if (lastHTMLSize != 0 && currentHTMLSize == lastHTMLSize)
			countStableSizeIterations++;
		else
			countStableSizeIterations = 0; //reset the counter

		if (countStableSizeIterations >= minStableSizeIterations) {
			//console.log("Page rendered fully..");
			break;
		}

		lastHTMLSize = currentHTMLSize;
		await page.waitForTimeout(checkDurationMsecs);
	}
};

//Require puppeteer
const puppeteer = require('puppeteer');

/**
 * Function that will return the rendered
 * HTML from testing the WP Rocket index file
 * along with information about standard fonts and
 * Google fonts stored in HTML comments for later removal
 * 
 * Also adds in contain-intrinsic-size information
 * 
 */

(async function main() {
	try {
		const browser = await puppeteer.launch({
			args: ['--disable-dev-shm-usage', '--no-sandbox', '--disable-setuid-sandbox'],
		});
		const page = await browser.newPage();

		//Variable to hold fonts
		var fonts = [];
		var googlefonts = {};

		//Enable interception
		await page.setRequestInterception(true);
		page.on('request', (request) => {
			
			// Store the request for fonts, so we can preload them
			if (/woff|ttf|otf/.test(request.url())) {
				fonts.indexOf(request.url()) === -1 ? fonts.push(request.url()) : "";
			}

			//Block external requests and images, as we don't need get to get the rendered CSS
			var host = process.argv[3];
			var regEx = new RegExp(host.replace(".","\\.") + '|file:|googleapis|data:');
			if (!regEx.test(request.url()) || /png|jpg|jpeg/.test(request.url())) {
				request.abort();
			} else {
				request.continue()
			}
		})

		//Save information on requests google font information
		page.on('response', async response => {
			if (/googleapis/.test(response.url())) {
				const text = await response.text();
				googlefonts[response.url()] = btoa(text);
			}
		});

		//Process the page from file
		await page.goto(process.argv[2], { waitUntil: 'networkidle0' });

		//Click on the body to trigger any JavaScript that requires user interaction
		await page.click('body')

		//Wait until fully rendered
		await waitTillHTMLRendered(page);

		//Wait for jQuery to be loaded
		const watchDog = page.waitForFunction('typeof(jQuery("<div/>").appendTo) != "undefined"');
		await watchDog;

		//Gather contain-instrinsic information
		await page.evaluate(_ => {
			window.scrollBy(0, 1000);
			styles = "<style data-intrinsic-lc=true>div.content-area > section { content-visibility: auto; contain-intrinsic-size: auto 1000px; }";
			divs = document.querySelectorAll("div.content-area > section"); for (i = 0; i < divs.length; i++) { styles += "div.content-area > section:nth-child(" + (i + 1) + ") { contain-intrinsic-size: auto " + divs[i].offsetHeight + "px   }" + ""; };
			styles += "</style>";
			jQuery(styles).appendTo(jQuery("head"));
		});

		//Set to mobile view incase that triggers further styles
		await page.setViewport({
			width: 480,
			height: 640,
			deviceScaleFactor: 1
		});

		//Get the rendered HTML
		var data = await page.evaluate(() => document.querySelector('*').outerHTML);

		//Append standard font info
		if (fonts.length > 0) {
			data = data + '<!-- FONTS' + JSON.stringify(fonts) + "-->";
		}

		//Append Google font info
		if (Object.keys(googlefonts).length > 0) {
			data = data + '<!-- GOOGLEFONTS' + JSON.stringify(googlefonts) + "-->";
		}

		//Log to console
		console.log(data);

		await browser.close();
	} catch (err) {
		console.error(err);
	}
})();