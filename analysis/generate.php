<?php
require_once __DIR__.'/_assets/common.php';
$resultFiles = array();
$repos       = json_decode(file_get_contents(__DIR__.'/_assets/repos.json'));

$GLOBALS['today'] = date('Y-m-d');
$checkoutDate     = $GLOBALS['today'];
$recordTrend      = false;
$runPHPCS         = true;
$runGit           = true;
$filterRepos      = array();
foreach ($_SERVER['argv'] as $arg) {
    if (substr($arg, 0, 7) === '--date=') {
        $checkoutDate = substr($arg, 7);
    } else if (substr($arg, 0, 8) === '--repos=') {
        $filterRepos = explode(',', substr($arg, 8));
    } else if ($arg === '--trend') {
        $recordTrend = true;
    } else if ($arg === '--no-phpcs') {
        $runPHPCS = false;
    } else if ($arg === '--no-git') {
        $runGit = false;
    }
}

if (empty($filterRepos) === true) {
    $repoCount = count($repos);
} else {
    $repoCount = count($filterRepos);
}

$GLOBALS['repoList'] = array();

$repoNum = 0;
foreach ($repos as $repo) {
    if (empty($filterRepos) === false && in_array($repo->url, $filterRepos) === false) {
        continue;
    }

    $repoNum++;
    echo 'Processing '.$repo->name." ($repoNum / $repoCount)".PHP_EOL;
    $resultFiles[$repo->url] = processRepo($repo, $checkoutDate, $runPHPCS, $runGit);
    echo PHP_EOL;

    $GLOBALS['repoList'][$repo->url] = $repo->name;
}

// Imports $metricText variable.
require_once __DIR__.'/_assets/metricText.php';
$GLOBALS['metric_text'] = $metricText;
$GLOBALS['colours']     = array(
                           '#2D3F50',
                           '#91A2B2',
                           '#D1D4DB',
                           '#E5E5E5',
                          );

$GLOBALS['num_repos'] = count($resultFiles);
natcasesort($GLOBALS['repoList']);
ksort($resultFiles, SORT_NATURAL | SORT_FLAG_CASE);

echo "Pre-processing result files".PHP_EOL;

$totals = array();
foreach ($resultFiles as $file) {
    $results = json_decode(file_get_contents($file), true);
    $repo    = $results['project']['path'];

    foreach ($results['metrics'] as $metric => $data) {
        if (empty($data['values']) === true) {
            continue;
        }

        if (isset($totals[$metric]) === false) {
            $totals[$metric] = array(
                                'total'       => 0,
                                'total_repos' => 0,
                                'values'      => array(),
                                'repos'       => array(),
                                'trends'      => array(),
                               );
        }

        $winner      = '';
        $winnerCount = 0;
        foreach ($data['values'] as $value => $count) {
            if (isset($totals[$metric]['values'][$value]) === false) {
                $totals[$metric]['values'][$value] = $count;
            } else {
                $totals[$metric]['values'][$value] += $count;
            }

            $totals[$metric]['total'] += $count;

            if ($count > $winnerCount) {
                $winner      = $value;
                $winnerCount = $count;
            }
        }

        if ($recordTrend === true) {
            $results['metrics'][$metric]['trends'][$checkoutDate] = $data['values'];
        }

        foreach ($data['trends'] as $date => $values) {
            if (isset($totals[$metric]['trends'][$date]) === false) {
                $totals[$metric]['trends'][$date] = array();
            }

            foreach ($values as $value => $count) {
                if (isset($totals[$metric]['trends'][$date][$value]) === false) {
                    $totals[$metric]['trends'][$date][$value] = $count;
                } else {
                    $totals[$metric]['trends'][$date][$value] += $count;
                }
            }
        }

        // Needed for sorting this result set later on.
        $results['metrics'][$metric]['winner'] = $winner;
        if (isset($totals[$metric]['repos'][$winner]) === false) {
            $totals[$metric]['repos'][$winner] = array();
        }

        $totals[$metric]['repos'][$winner][$repo] = round(($winnerCount / $data['total'] * 100), 2);
        $totals[$metric]['total_repos']++;

    }//end foreach

    file_put_contents($file, jsonpp(json_encode($results, JSON_FORCE_OBJECT)));

}//end foreach

foreach ($totals as $metric => $data) {
    $winner      = '';
    $winnerCount = 0;
    foreach ($data['values'] as $value => $count) {
        if ($count > $winnerCount) {
            $winner      = $value;
            $winnerCount = $count;
        }
    }

    $totals[$metric]['winner'] = $winner;

    if ($recordTrend === true) {
        $totals[$metric]['trends'][$checkoutDate] = $data['values'];
        ksort($totals[$metric]['trends']);
    }
}

$GLOBALS['totals'] = $totals;

echo "Generating HTML files".PHP_EOL;

foreach ($resultFiles as $file) {
    $results = json_decode(file_get_contents($file), true);
    $repo    = $results['project']['path'];
    echo "\t=> Processing result file for $repo: $file".PHP_EOL;
    $output = generateReport($results, $repo);
    file_put_contents(__DIR__.'/'.$repo.'/index.html', $output);
}


if (empty($filterRepos) === false) {
    exit;
}

$filename = __DIR__.'/results.json';
file_put_contents($filename, jsonpp(json_encode($totals, JSON_FORCE_OBJECT)));

echo "\t=> Processing main result file: $filename".PHP_EOL;
$output = generateReport($totals);
file_put_contents(__DIR__.'/index.html', $output);





function generateReport($results, $repo=null)
{
    $html = '';
    $js   = 'var valOptions = {showTooltips:false,animation:false,segmentStrokeWidth:3,percentageInnerCutout:55};'.PHP_EOL;
    $js  .= 'var repoOptions = {showTooltips:false,animation:false,segmentStrokeWidth:3,percentageInnerCutout:50};'.PHP_EOL;
    $js  .= 'var trendOptions = {pointHitDetectionRadius:5,multiTooltipTemplate:"<%=datasetLabel%>: <%=value%>%",tooltipFillColor:"#E9E9E9",tooltipFontColor:"#000",tooltipFontFamily:"arial",tooltipTitleFontFamily:"arial",tooltipTitleFontColor:"#000",tooltipCornerRadius:0,multiTooltipKeyBackground:"#000",animation:false,scaleLineColor:"#C5C5C5",scaleLabel:"<%=value%>%",scaleFontSize:11,scaleFontFamily:"arial",scaleGridLineColor:"#C5C5C5",bezierCurve:false,pointDot:true,datasetFill:false};'.PHP_EOL;
    $js  .= 'var perfectTrendOptions = {pointHitDetectionRadius:5,tooltipTemplate:"<%=label%>: <%=value%>%",animation:false,scaleLineColor:"#C5C5C5",scaleLabel:"<%=value%>%",scaleFontSize:11,scaleFontFamily:"arial",scaleGridLineColor:"#C5C5C5",bezierCurve:false,pointDot:true,datasetFill:false,scaleOverride:true,scaleSteps:5,scaleStepWidth:20,scaleStartValue:0};'.PHP_EOL;

    $html .= '<div id="all" class="listBoxWrap">'.PHP_EOL;
    $html .= '    <div class="listBoxContent">'.PHP_EOL;
    $html .= '        <div class="listBoxClose" onclick="hideListBox();"></div>'.PHP_EOL;
    $html .= '        <div class="listBoxHeader">'.PHP_EOL;
    $html .= '            <h2>View project specific report</h2>'.PHP_EOL;
    $html .= '        </div>'.PHP_EOL;
    $html .= '        <div id="alllistBoxListWrap" class="listBoxListWrap">'.PHP_EOL;
    $html .= '            <ul class="listBoxList">'.PHP_EOL;

    foreach ($GLOBALS['repoList'] as $repoURL => $repoName) {
        $href = $repoURL.'/index.html';
        if ($repo !== null) {
            $href = '../../'.$href;
        }

        $html .= '<li><a href="'.$href.'"><div class="td1">'.$repoName.'</div></a></li>'.PHP_EOL;
    }

    $html .= '    </ul>'.PHP_EOL;
    $html .= '  </div>'.PHP_EOL;
    $html .= '  </div>'.PHP_EOL;
    $html .= '  </div>'.PHP_EOL;

    $metricTable = '';

    if ($repo === null) {
        $metrics = $results;
    } else {
        $metrics = $results['metrics'];
    }

    $consistency = array(
                    'pos' => 0,
                    'neg' => 0,
                   );

    uasort($metrics, 'sortMetrics');
    $chartNum = 0;
    foreach ($metrics as $metric => $data) {
        if (empty($data['values']) === true || $data['total'] === 0) {
            continue;
        }

        $metricid = preg_replace('/[^0-9a-zA-Z]/', '-', strtolower($metric));

        // Add results to the consistency score.
        // Skip line length as it is more informative only.
        if ($metricid !== 'line-length') {
            foreach ($data['values'] as $value => $count) {
                if ($value === $data['winner']) {
                    $consistency['pos'] += $count;
                } else {
                    $consistency['neg'] += $count;
                }
            }
        }

        $description = '';
        if (isset($GLOBALS['metric_text'][$metric]['description']) === true) {
            $description = $GLOBALS['metric_text'][$metric]['description'];
        }

        $items = 'items';
        if (isset($GLOBALS['metric_text'][$metric]['items']) === true) {
            $items = $GLOBALS['metric_text'][$metric]['items'];
        }

        $chartNum++;

        $html .= '<div id="'.$metricid.'" class="conventionWrap">'.PHP_EOL;
        $html .= '<div class="conventionDetails">'.PHP_EOL;
        $html .= '  <h2>'.$metric.'</h2>'.PHP_EOL;
        $html .= '  <p>'.$description.'</p>'.PHP_EOL;
        $html .= '  <div class="currentData">'.PHP_EOL;

        if ($repo !== null) {
            if ($data['winner'] === $GLOBALS['totals'][$metric]['winner']) {
                $html .= '    <div class="conventionStatusInProject project true">'.PHP_EOL;
                $html .= '      <p class="projectStatusText">This project is using the popular method for this convention</p>'.PHP_EOL;
                $html .= '      <span title="This project is using the popular method for this convention" class="conventionStatus"></span>'.PHP_EOL;
                $html .= '    </div>'.PHP_EOL;
            } else {
                $html .= '    <div class="conventionStatusInProject project false">'.PHP_EOL;
                $html .= '      <span title="This project is not using the popular method for this convention" class="conventionStatus"></span>'.PHP_EOL;
                $html .= '    </div>'.PHP_EOL;
            }
        }

        $html .= '    <div class="tag">Current</div>'.PHP_EOL;
        $html .= '    <div class="currentDataWrap">'.PHP_EOL;
        $html .= '      <div class="currentChart">'.PHP_EOL;
        $html .= '        <canvas class="chart-value" id="chart'.$chartNum.'" width="290" height="290"></canvas>'.PHP_EOL;

        if ($repo === null) {
            $html .= '        <canvas class="chart-repo" id="chart'.$chartNum.'r" width="154" height="154"></canvas>'.PHP_EOL;
        }

        $html .= '      </div>'.PHP_EOL;
        $html .= '      <div class="tableContainer">'.PHP_EOL;
        $html .= '        <table class="statsTable">'.PHP_EOL;
        $html .= '          <tr class="screenHide">'.PHP_EOL;
        $html .= '            <th>Key</th>'.PHP_EOL;
        $html .= '            <th>Method</th>'.PHP_EOL;
        $html .= '            <th>Use</th>'.PHP_EOL;
        $html .= '          </tr>'.PHP_EOL;

        $valsData      = 'var data = [';
        $repoData      = 'var data = [';
        $trendData     = '';
        $repoHTML      = '';
        $repoResetCode = '';

        $valueNum  = 0;
        $other     = 0;
        $numValues = count($data['values']);

        $sort = SORT_STRING;
        if (isset($GLOBALS['metric_text'][$metric]['sort']) === true) {
            $sort = $GLOBALS['metric_text'][$metric]['sort'];
        }

        $perfectScore = true;

        // Some significant values in the trend data may not register any more, so they
        // will be lost from the trend graph if they are not inserted back into
        // the current value list.
        foreach ($data['trends'] as $date => $trendValues) {
            $trendTotal = array_sum($trendValues);
            foreach ($trendValues as $trendValue => $trendCount) {
                if (isset($data['values'][$trendValue]) === false) {
                    $score = round(($trendCount / $trendTotal * 100), 2);
                    if ($score > 1) {
                        $data['values'][$trendValue] = 0;
                    }
                }
            }
        }

        ksort($data['values'], $sort);
        foreach ($data['values'] as $value => $count) {
            if (isset($GLOBALS['colours'][$valueNum]) === false) {
                $colour = '#FFFFFF';
            } else {
                $colour = $GLOBALS['colours'][$valueNum];
            }

            if ($repo === null) {
                $valueid = preg_replace('/[^0-9a-zA-Z]/', '-', strtolower($value));
                if (isset($data['repos'][$value]) === true) {
                    $numRepos     = count($data['repos'][$value]);
                    $percentRepos = round($numRepos / $data['total_repos'] * 100, 2);

                    if ($numRepos === 1) {
                        $title = '1 project prefers';
                    } else {
                        $title = "$numRepos projects prefer";
                    }

                    $repoHTML .= '<div id="'.$metricid.'-'.$valueid.'-repos" class="listBoxWrap">'.PHP_EOL;
                    $repoHTML .= '    <div class="listBoxContent">'.PHP_EOL;
                    $repoHTML .= '        <div class="listBoxClose" onclick="hideListBox();"></div>'.PHP_EOL;
                    $repoHTML .= '        <div class="listBoxHeader">'.PHP_EOL;
                    $repoHTML .= '            <h3>'.$title.' <i>'.$value.'</i></h3>'.PHP_EOL;
                    $repoHTML .= '        </div>'.PHP_EOL;
                    $repoHTML .= '        <div id="'.$metricid.'-'.$valueid.'-reposlistBoxListWrap" class="listBoxListWrap">'.PHP_EOL;
                    $repoHTML .= '            <ul class="listBoxList">'.PHP_EOL;

                    uksort($data['repos'][$value], 'sortRepos');
                    foreach ($data['repos'][$value] as $repoURL => $percent) {
                        $href      = $repoURL.'/index.html#'.$metricid;
                        $repoHTML .= '<li><a href="'.$href.'"><div class="td1">'.$GLOBALS['repoList'][$repoURL].'</div><div class="td2">'.$percent.'%</div></a></li>'.PHP_EOL;
                    }

                    $repoHTML .= '    </ul>'.PHP_EOL;
                    $repoHTML .= '  </div>'.PHP_EOL;
                    $repoHTML .= '  </div>'.PHP_EOL;
                    $repoHTML .= '  </div>'.PHP_EOL;
                } else {
                    $numRepos     = 0;
                    $percentRepos = 0;
                }//end if

                $repoData .= '{value:'.$percentRepos.',color:"'.$colour.'",label:"'.$value.'"},';
            }//end if

            $percent = round($count / $data['total'] * 100, 2);
            if ($numValues > 4 && $percent < 1) {
                $other += $count;
                continue;
            }

            $count     = number_format($count, 0, '', ',');
            $valsData .= '{value:'.$percent.',color:"'.$colour.'",label:"'.$value.'"},';

            $html .= '      <tr title="'.$count.' '.$items.'">'.PHP_EOL;

            if ($value === $data['winner']) {
                $html .= '        <td class="key keyPopular" style="background-color:'.$colour.'" title="Most popular method"><span class="screenHide">'.$value.' - Most popular method</span></td>'.PHP_EOL;
            } else {
                $html .= '        <td class="key" style="background-color:'.$colour.'"><span class="screenHide">'.$value.'</span></td>'.PHP_EOL;
            }

            $html .= '        <td class="result">'.$value.'</td>'.PHP_EOL;

            // Sometimes 0 doesn't really mean 0.
            if ($count > 0 && $percent === 0.00) {
                $percent = '< 0.01';
            }

            $html .= '        <td class="value">'.$percent.'%';
            if ($repo === null) {
                $html .= '<br/>';
                if ($numRepos > 0) {
                    $html .= '<a href="" onclick="showListBox(\''.$metricid.'-'.$valueid.'-repos\');return false;">';
                } else {
                    $html .= '<span class="preferrNone">';
                }

                $html .= 'preferred by '.$percentRepos.'% of projects';
                if ($numRepos > 0) {
                    $html .= '</a>';
                } else {
                    $html .= '</span>';
                }
            }

            $html .= '</td>'.PHP_EOL;
            $html .= '      </tr>'.PHP_EOL;

            $trendData .= "{label:\"$value\",strokeColor:\"$colour\",pointStrokeColor:\"#FFF\",pointColor:\"$colour\",pointHighlightFill:\"#FFF\",pointHighlightStroke:\"$colour\",data:[";
            ksort($data['trends']);
            foreach ($data['trends'] as $date => $trendValues) {
                $trendTotal = array_sum($trendValues);
                $addedValue = false;
                foreach ($trendValues as $trendValue => $trendCount) {
                    if ($trendValue !== $value) {
                        continue;
                    }

                    $score      = round(($trendCount / $trendTotal * 100), 2);
                    $trendData .= $score.',';
                    $addedValue = true;
                    if ((int) $score !== 100) {
                        $perfectScore = false;
                    }

                    break;
                }

                if ($addedValue === false) {
                    // Percentage must have been 0 as no values recorded.
                    $trendData .= '0.00,';
                }
            }//end foreach

/*
            if ($date !== $GLOBALS['today']) {
                $trendData .= $percent.',';
                if ((int) $percent !== 100) {
                    $perfectScore = false;
                }
            }
*/

            $trendData  = rtrim($trendData, ',');
            $trendData .= ']},';
            $valueNum++;
        }//end foreach

        #if ($perfectScore === true) {
        #    // Add fake data so the perfect score line shows up.
        #    $trendData .= '{strokeColor:"#FFF",pointStrokeColor:"#FFF",pointColor:"#FFF",data:[';
        #    $trendData .= str_repeat('0,', count($data['trends']));
        #    $trendData  = rtrim($trendData, ',');
        #    $trendData .= ']},';
        #}

        $valsData  = substr($valsData, 0, -1);
        $valsData .= ']'.PHP_EOL;

        $js .= $valsData;
        $js .= 'var c = document.getElementById("chart'.$chartNum.'").getContext("2d");'.PHP_EOL;
        $js .= 'new Chart(c).Doughnut(data,valOptions);'.PHP_EOL;

        if ($repo === null) {
            $html      = str_replace('((repoResetCode))', $repoResetCode, $html);
            $repoData  = substr($repoData, 0, -1);
            $repoData .= ']'.PHP_EOL;
            $js       .= $repoData;
            $js       .= 'var c = document.getElementById("chart'.$chartNum.'r").getContext("2d");'.PHP_EOL;
            $js       .= 'new Chart(c).Doughnut(data,repoOptions);'.PHP_EOL;
        }

        $js      .= 'var data = {labels:[';
        $numDates = count($data['trends']);
        $dateStep = ceil($numDates / 40);
        $dateNum  = 0;
        foreach (array_keys($data['trends']) as $date) {
            if ($dateNum === 0 || $dateNum % $dateStep === 0) {
                $time = strtotime($date);
                $js  .= '"'.date('d M', $time).'",';
            } else {
                $js .= '"",';
            }

            $dateNum++;
            continue;
        }

        #if (strtotime($GLOBALS['today']) === $time) {
            $js = rtrim($js, ',');
        #} else {
        #    $js .= '"'.date('d M', strtotime($GLOBALS['today'])).'"';
        #}

        $trendData = rtrim($trendData, ',');
        $js       .= "],datasets:[$trendData]};".PHP_EOL;
        $js       .= 'var c = document.getElementById("chart'.$chartNum.'t").getContext("2d");'.PHP_EOL;
        if ($perfectScore === true) {
            $js .= 'new Chart(c).Line(data,perfectTrendOptions);'.PHP_EOL;
        } else {
            $js .= 'new Chart(c).Line(data,trendOptions);'.PHP_EOL;
        }

        if ($other > 0) {
            $percent = round($other / $data['total'] * 100, 2);
            $other   = number_format($other, 0, '', ',');
            $html   .= '<tr title="'.$other.' '.$items.'">'.PHP_EOL;
            $html   .= '  <td class="key"><span class="screenHide">Other</span></td>'.PHP_EOL;
            $html   .= '  <td class="result">other</td>'.PHP_EOL;
            $html   .= '  <td class="value">'.$percent.'%</td>'.PHP_EOL;
            $html   .= '</tr>'.PHP_EOL;
        }

        $totalItems = number_format($data['total'], 0, '', ',').' '.$items;
        if ($repo === null) {
            $totalItems .= ' in '.$data['total_repos'].' projects';
        }

        $html .= '        </table>'.PHP_EOL;
        $html .= "        <p class=\"statsInfo\">Based on $totalItems</p>".PHP_EOL;
        $html .= '      </div>'.PHP_EOL;
        $html .= '    </div>'.PHP_EOL;
        $html .= $repoHTML;
        $html .= '  </div>'.PHP_EOL;
        $html .= '  <div class="historicalData">'.PHP_EOL;
        $html .= '    <div class="tag">Historical</div>'.PHP_EOL;
        $html .= '    <div class="historicalChart">'.PHP_EOL;
        $html .= '      <canvas class="chart-trend" id="chart'.$chartNum.'t" width="860" height="185"></canvas>'.PHP_EOL;
        $html .= '    </div>'.PHP_EOL;
        $html .= '  </div>'.PHP_EOL;
        $html .= '</div>'.PHP_EOL;
        $html .= '</div>'.PHP_EOL;
    }//end foreach

    $score = round(($consistency['pos'] / ($consistency['pos'] + $consistency['neg']) * 100), 2);
    if ($score >= 97) {
        $grade = 'A+';
    } else if ($score >= 93) {
        $grade = 'A';
    } else if ($score >= 90) {
        $grade = 'A-';
    } else if ($score >= 87) {
        $grade = 'B+';
    } else if ($score >= 83) {
        $grade = 'B';
    } else if ($score >= 80) {
        $grade = 'B-';
    } else if ($score >= 77) {
        $grade = 'C+';
    } else if ($score >= 73) {
        $grade = 'C';
    } else if ($score >= 70) {
        $grade = 'C-';
    } else if ($score >= 67) {
        $grade = 'D+';
    } else if ($score >= 63) {
        $grade = 'D';
    } else if ($score >= 60) {
        $grade = 'D-';
    } else {
        $grade = 'F';
    }

    echo "Score: $score ($grade)\n";

    ksort($metrics);
    $sidebar = '';

    // Create the flyout sidebar.
    foreach ($metrics as $metric => $data) {
        if (empty($data['values']) === true || $data['total'] === 0) {
            continue;
        }

        $popular = '';

        $metricid   = str_replace(' ', '-', strtolower($metric));
        $winPercent = round($data['values'][$data['winner']] / $data['total'] * 100, 2);
        $sidebar   .= '<li><a href="#'.$metricid.'">';

        if ($repo !== null) {
            if ($data['winner'] === $GLOBALS['totals'][$metric]['winner']) {
                $sidebar .= '<div class="tdpop popular" title="This project is using the popular method for this convention"></div>';
            } else {
                $sidebar .= '<div class="tdpop"></div>';
            }
        }
        
        $sidebar .= '<div class="td1">'.$metric.'</div><div class="td2"><span class="screenHide">Method: </span>'.$data['winner'].'</div><div class="td3"><span class="screenHide">Value: </span>'.$winPercent.'%</div></a></li>'.PHP_EOL;
    }//end foreach

    if ($repo === null) {
        $intro  = '<p class="overviewText"><a href="https://github.com/squizlabs/PHP_CodeSniffer">PHP_CodeSniffer</a>, using a custom coding standard and report, was used to record various coding conventions across '.$GLOBALS['num_repos'].' PHP projects.</p>'.PHP_EOL;
        $intro .= '<ul class="reportLinkList">'.PHP_EOL;
        $intro .= '  <li><a href="" onclick="showListBox(\'all\'); return false;" class="reportLink reportProject">View Project Specific Report</a></li>'.PHP_EOL;
        $intro .= '</ul>'.PHP_EOL;
        $intro .= '<div class="divider"></div>'.PHP_EOL;
        $intro .= '<div id="reportInstructionsWrap" class="reportInstructionsWrap collapsed">'.PHP_EOL;
        $intro .= '  <a href="" onclick="toggleInstructions(); return false;"><h2>How to read this report</h2></a>'.PHP_EOL;
        $intro .= '  <ul class="reportInstructions">'.PHP_EOL;
        $intro .= '    <li class="instructionItem graphInstructions">The graphs for each coding convention show the percentage of each style variation used across all projects (the outer ring) and the percentage of projects that primarily use each variation (the inner ring). Clicking the <em>preferred by</em> line under each style variation will show a list of projects that primarily use it, with the ability to click through and see a coding convention report for the project.</li>'.PHP_EOL;
        $intro .= '    <li class="instructionItem overviewPanel"><a href="" onclick="showFlyout(); return false;">See an overview</a> of the most popular methods for coding conventions in this report</li>'.PHP_EOL;
        $intro .= '    <li class="instructionItem rawData">You can <a href="./results.json">view the raw data</a> used to generate this report, and use it in any way you want.</li>'.PHP_EOL;
        $intro .= '  </ul>'.PHP_EOL;
        $intro .= '</div>'.PHP_EOL;

        $footer    = 'Report generated on '.date('r');
        $title     = 'Analysis of Coding Conventions';
        $heading   = 'Analysis of Coding Conventions';
        $assetPath = '';
    } else {
        $intro  = '<p class="overviewText"><a href="https://github.com/squizlabs/PHP_CodeSniffer">PHP_CodeSniffer</a>, using a custom coding standard and report, was used to record various coding conventions across '.$GLOBALS['num_repos'].' PHP projects.</p>'.PHP_EOL;
        $intro .= '<ul class="reportLinkList">'.PHP_EOL;
        $intro .= '  <li><a href="../../index.html" class="reportLink reportCombined">View Combined Report ('.$GLOBALS['num_repos'].' PHP Projects)</a></li>'.PHP_EOL;
        $intro .= '  <li><a href="" onclick="showListBox(\'all\'); return false;" class="reportLink reportProject">View Project Specific Report</a></li>'.PHP_EOL;
        $intro .= '</ul>'.PHP_EOL;
        $intro .= '<div class="divider"></div>'.PHP_EOL;
        $intro .= '<div id="reportInstructionsWrap" class="reportInstructionsWrap collapsed">'.PHP_EOL;
        $intro .= '  <a href="" onclick="toggleInstructions(); return false;"><h2>How to read this report</h2></a>'.PHP_EOL;
        $intro .= '  <ul class="reportInstructions">'.PHP_EOL;
        $intro .= '    <li class="instructionItem graphInstructions">The graphs for each coding convention show the percentage of each style variation used throughout the project.</li>'.PHP_EOL;
        $intro .= '    <li class="instructionItem overviewPanel"><a href="" onclick="showFlyout(); return false;">See an overview</a> of the most popular methods for coding conventions in this project</li>'.PHP_EOL;
        $intro .= '    <li class="instructionItem rawData">You can <a href="./results.json">view the raw data</a> used to generate this report, and use it in any way you want.</li>'.PHP_EOL;
        $intro .= '  </ul>'.PHP_EOL;
        $intro .= '</div>'.PHP_EOL;

        $commitid  = $results['project']['commitid'];
        $footer    = 'Report generated on '.date('r')."<br/>Using master branch of <a href=\"https://github.com/$repo\">$repo</a> @ commit <a href=\"https://github.com/$repo/commit/$commitid\">$commitid</a>";
        $title     = $GLOBALS['repoList'][$repo].' - Analysis of Coding Conventions';
        $heading   = 'Analysis of Coding Conventions for <span class="repoName">'.$GLOBALS['repoList'][$repo].'</span>';
        $assetPath = '../../';
    }//end if

    $output = file_get_contents(__DIR__.'/_assets/index.html.template');
    $output = str_replace('((title))', $title, $output);
    $output = str_replace('((heading))', $heading, $output);
    $output = str_replace('((intro))', $intro, $output);
    $output = str_replace('((sidebar))', $sidebar, $output);
    $output = str_replace('((html))', $html, $output);
    $output = str_replace('((footer))', $footer, $output);
    $output = str_replace('((js))', $js, $output);
    $output = str_replace('((assetPath))', $assetPath, $output);
    return $output;

}//end generateReport()
