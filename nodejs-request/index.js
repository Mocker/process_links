const util = require('util');
const { exec, spawn } = require('child_process');
const request = require('superagent');
const lineByLine = require('n-readlines');
const fs = require('fs');
const readline = require('readline');


const config = {
    // URL of the site
    //https://www.zillow.com/homedetails/577-Mulberry-Dr-Fleming-Island-FL-32003/43701102_zpid/
    url_dir: '../urls', //scan all files in the directory
    url_order: 'desc',
    //url_random: 'true', //will randomize which url file it downloads from. For large datasets however this will result in a lot of overlap and make it hard to see how many are actually done
    //url_file: '../mongo/zillow/listings.json', //not used, we split it up into multiple files in url_dir
    listings_dir: '../listings/', //where we save the files to, sorted by directory for zip
    // URL of Scrapoxy
    proxy: 'http://localhost:8888',
    proxyAdmin: 'http://localhost:8889',
    proxyAuth: 'Y3Jhd2xfbGlua3M=',
    // HTTPS over HTTP
    tunnel: false,
    log_dir: '../logs/',
};

const MIN_PROXIES_REQUIRED=7;
let proxies = [];
const MAX_ACTIVE_REQUESTS=6;
let activeRequests = 0;
const MAX_LISTINGS_READ=10000;
let listingsRead = 0;
const MAX_ZIPS_READ=5000;
let zipsRead = 0;
const USER_AGENT_IOS = '-A "Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_3 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8J2 Safari/6533.18.5"';
const MAX_FAILED_IPS=15;
let failed_ips=0;
let existing_listings=0;

const start = Date.now();
const log_file = config.log_dir+(new Date()).toLocaleString().replace(/[^\d\:\w]/g,'_')+'.log';
let liner = null;

let url_files = [];
let file_i = -1;
let curl_logs = [];

fs.appendFileSync(log_file, `Beginning run ${MIN_PROXIES_REQUIRED} min proxies, ${MAX_LISTINGS_READ} max_listings, ${MAX_ZIPS_READ} max zips, ${MAX_ACTIVE_REQUESTS} active requests. ${url_files.length} total json files to parse\n`,'utf8');

console.log("Checking there are available proxies..");
waitForProxies( async (res) => {
    url_files = await new Promise( (resolve, reject) => {
        fs.readdir(config.url_dir, (err, items) => {
            if(err) throw(err);
            resolve(items);
        });
    });
    if(config.url_random){
        url_files = shuffle(url_files);
    }
    file_i = (config.url_order=='desc') ? url_files.length  : -1;
    fs.appendFileSync(log_file, ` ${url_files.length} total json files to parse\n`,'utf8');
    proxies = res;
    let curProxy = 0;
    fs.appendFileSync(log_file, `Proxies ready: ${JSON.stringify(proxies)}\n`,'utf8');
    line = getNextLine();
    let failuresPerZip = 1;
    while( zipsRead++ < MAX_ZIPS_READ && listingsRead < MAX_LISTINGS_READ && failed_ips < MAX_FAILED_IPS && line  ) {
       
        zip = JSON.parse(line);
        dir = config.listings_dir+zip.Zip;
        if(!fs.existsSync(dir) ) fs.mkdirSync(dir);
        failuresPerZip = 0; 
        console.log("Beginning zip",zip.Zip,zip.Homes.length);
        fs.appendFileSync(log_file, `Beginning zip ${zip.Zip} with ${zip.Homes.length} listings\n`, 'utf8');
        if( fs.existsSync(dir+'/'+'done.txt') ) {
            console.log(`Found done file, skipping zip ${zip.Zip}`);
            fs.appendFileSync(log_file, `Found done file, skipping zip ${zip.Zip}\n`, 'utf8');
            zipsRead--;
            line = getNextLine();
            continue;
        }
        while(zip.Homes.length > 0 && listingsRead < MAX_LISTINGS_READ && failed_ips < MAX_FAILED_IPS) {
            const url = zip.Homes.pop();
            const a = url.split('/');
            let fname = a[4];
            if(url.indexOf("community") > -1){
                fname += a[5];
            }
            fname = dir + '/'+fname;
            if(fs.existsSync(fname+'.html')){
                console.log("Exists "+fname);
                existing_listings++;
                continue;
            }
            console.log(`Next url ${url} # ${listingsRead, proxies.length} > ${MIN_PROXIES_REQUIRED} proxies. ${activeRequests} / ${MAX_ACTIVE_REQUESTS} requests. ` );
            curl_logs.push( `Next url ${url} # ${listingsRead, proxies.length} > ${MIN_PROXIES_REQUIRED} proxies. ${activeRequests} / ${MAX_ACTIVE_REQUESTS} requests. `);
            while( proxies.length < MIN_PROXIES_REQUIRED ) {
                const res = await getProxyStatus();
                proxies = res.body;
                await sleep(5000);
            }
            while( activeRequests >= MAX_ACTIVE_REQUESTS) {
                await(sleep(500));
            }
            activeRequests++;
            listingsRead++;
            if(curProxy >= proxies.length) curProxy = 0;
            console.log(proxies[curProxy].address);
            let waitForProxyCounter = 0;
            if(!proxies[curProxy].address || !proxies[curProxy].address.hostname){
                while( !proxies[curProxy].address || !proxies[curProxy].address.hostname ) {
                    waitForProxyCounter++;
                    if(waitForProxyCounter > proxies.length) {
                        break;
                    }
                    curProxy++;
                    if(curProxy >= proxies.length) curProxy = 0;
                    sleep(5000);
                }
            }
            if(waitForProxyCounter > proxies.length) {
                console.log("Could not find any proxies with an active IP\n");
                curl_logs.push(`Could not find any proxies with an active IP. Stopping the job\n`);
                failed_ips = MAX_FAILED_IPS;
                break;
            }
            let curl = spawn('curl', 
                ['-L','-s','-x','http://'+proxies[curProxy].address.hostname+':'+proxies[curProxy].address.port,'-A',USER_AGENT_IOS,'-D',fname+'.headers','-o',fname+'.html',url],
                { });
            curl.stdout.on('data', (data)=>{ console.log("Curl data: ",data); });
            curl.stderr.on('data', (data)=>{ console.log("Curl error: ",data); });
            const usedProxy = proxies[curProxy];
            curl.on('close', (val) => {
                    //check headers file for http status
                    let lineReader = readline.createInterface({
                        input: fs.createReadStream( fname+'.headers'),
                    });
                    let lineCounter=0;
                    lineReader.on('line', function(line){
                        lineCounter++;
                        if(/HTTP\/[\d\.]+\s\d+/.test(line) && line.indexOf('HTTP/1.1 200 Connection established')<0 ){
                            console.log("status "+usedProxy.name+": ",line);
                            curl_logs.push( `[${line}] : ${zip.Zip} - ${fname} - ${usedProxy.name} - ${usedProxy.address.hostname}` );
                            fs.writeFile(fname+'.proxy', JSON.stringify(usedProxy), (err) => {
                                if(err) console.log("Could not write proxy info to "+fname+'.proxy - ',err);
                            })
                            lineReader.close();
                            lineReader.removeAllListeners();
                            if( !/HTTP\/[\d\.]+\s200/.test(line) ){
                                //move html file so next run it tries it again
                                failuresPerZip++;
                                console.log("Failed "+fname+" - IP: "+usedProxy.address.hostname);
                                curl_logs.push(`FAILED ${zip.Zip} - ${fname} - ${usedProxy.address.hostname} - ${line}`);
                                failed_ips++;
                                listingsRead--;
                                if( ! /\s404/.test(line) ) {
                                    stopProxy(usedProxy.name).then( (response) => {
                                        console.log("Stopped Proxy", response.body);
                                        curl_logs.push(`Stopped proxy: ${response.body}`);
                                    }).catch( (reason) => {
                                        console.log("ERROR STOPPING PROXY: ", reason);
                                        curl_logs.push(`ERROR STOPPING PROXY: ${reason}`);
                                    });
                                }
                                fs.rename( fname+'.html', fname+'.FAILED.html', (err)=>{ console.log("Could not rename file", err); });
                            }
                        }
                    }) ;
                    activeRequests--;
            });
            curProxy++;
        }
        if(failuresPerZip==0){
            fs.writeFileSync(dir+'/'+'done.txt', ' ');
        }
        if(curl_logs.length > 0){
            for( line in curl_logs) {
                fs.appendFileSync(log_file, curl_logs[line]+"\n", 'utf8');
            }
            curl_logs = [];
        }
        line = getNextLine();
    }
    for( line in curl_logs) {
        fs.appendFileSync(log_file, curl_logs[line]+"\n", 'utf8');
    }
    let folderSize = await new Promise( (resolve, reject) => {
        exec(' du -mh '+config.listings_dir, (err, stdout, stderr) => {
            if(err) reject(err);
            resolve(stdout);
        });
    });
    fs.appendFileSync(log_file, `Finished read ${listingsRead} urls in ${Date.now()-start} ms and size ${folderSize} \n- ${failed_ips} bad responses \n- ${existing_listings} existing \n- ${(Date.now()-start)/listingsRead} ms/listings \n- ${parseInt(folderSize.replace(/[^\d\.]/,''))/(listingsRead+existing_listings)} size/listing\n`, 'utf8');
    console.log(`Finished read ${listingsRead} urls in ${Date.now()-start} ms and size ${folderSize} - ${(Date.now()-start)/listingsRead} ms/listings \n- ${failed_ips} bad responses - ${existing_listings} existing \n- ${parseInt(folderSize.replace(/[^\d\.]/,''))/(listingsRead+existing_listings)} size/listing\n`);
    
    
    if(liner) liner.close();
    console.log("Waiting for any active requests to finish up");
    while(activeRequests > 0 ){
        await sleep(1000);
    }
    process.exit(0);
    
});

function getNextLine(){
    let line = null;
    if( !liner || !(line = liner.next() ) ){
        if(config.url_order=='desc'){
            file_i--;
            if(file_i >= 0) {
                liner = new lineByLine(config.url_dir+'/'+url_files[file_i]);
            } else {
                return null;
            }
        } else if(config.url_order=='asc'){
            file_i++;
            if(file_i < url_files.length) {
                liner = new lineByLine(config.url_dir+'/'+url_files[file_i]);
            } else {
                return null;
            }
        }
        fs.appendFile(log_file, `Began parsing new json file # ${file_i} - ${url_files[file_i]}`,'utf8',(err)=>{});
        
    }
    return liner.next();
    
}

function shuffle(a) {
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

function waitForProxies(cb){
    getProxyStatus().then( (res) => {
        if( Array.isArray(res.body) && res.body.length >= MIN_PROXIES_REQUIRED ) {
            cb( res.body );
        } else {
            sleep(5000).then(
                ()=> { waitForProxies(cb); }
            );
        }
    }).catch( (e) => {
        console.log("Caught error waiting", e.toString() );
    });
}

async function getProxyStatus() {
    return request.get(config.proxyAdmin+'/api/instances')
        .set('Authorization', config.proxyAuth)
        .set('Accept', 'application/json');
}


async function stopProxy( proxyName) {
    return request.post(config.proxyAdmin+'/api/instances/stop')
        .set('Authorization', config.proxyAuth)
        .set('Accept', 'application/json')
        .send({ name: proxyName });
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}