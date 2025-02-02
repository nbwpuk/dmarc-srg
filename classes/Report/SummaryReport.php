<?php

/**
 * dmarc-srg - A php parser, viewer and summary report generator for incoming DMARC reports.
 * Copyright (C) 2020 Aleksey Andreev (liuch)
 *
 * Available at:
 * https://github.com/liuch/dmarc-srg
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * =========================
 *
 * This file contains SummaryReport class
 *
 * @category API
 * @package  DmarcSrg
 * @author   Aleksey Andreev (liuch)
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 */

namespace Liuch\DmarcSrg\Report;

use Liuch\DmarcSrg\Statistics;

/**
 * This class is for generating summary data for the specified period and domain
 */
class SummaryReport
{
    private $stat    = null;
    private $domain  = null;
    private $subject = '';

    /**
     * Constructor
     *
     * @param Domain $domain The domain for which the report is created
     * @param string $period The period for which the report is created
     *                       Must me one of the following values: `lastweek`, `lastmonth`, and `lastndays:N`
     *                       where N is the number of days the report is created for
     */
    public function __construct($domain, string $period)
    {
        if (!$domain->exists()) {
            throw new \Exception('Domain "' . $domain->fqdn() . '" does not exist', -1);
        }

        $stat = null;
        $subject = '';
        switch ($period) {
            case 'lastweek':
                $stat = Statistics::lastWeek($domain);
                $subject = ' weekly';
                break;
            case 'lastmonth':
                $stat = Statistics::lastMonth($domain);
                $subject = ' monthly';
                break;
            default:
                $av = explode(':', $period);
                if (count($av) === 2 && $av[0] === 'lastndays') {
                    $ndays = intval($av[1]);
                    if ($ndays <= 0) {
                        throw new \Exception('The parameter "days" has an incorrect value', -1);
                    }
                    $stat = Statistics::lastNDays($domain, $ndays);
                    $subject = sprintf(' %d day%s', $ndays, ($ndays > 1 ? 's' : ''));
                }
                break;
        }
        if (!$stat) {
            throw new \Exception('The parameter "period" has an incorrect value', -1);
        }
        $this->stat = $stat;
        $this->domain = $domain;
        $this->subject = "DMARC{$subject} digest for {$domain->fqdn()}";
    }

    /**
     * Returns the report data as an array
     *
     * @return array
     */
    public function toArray(): array
    {
        $res = [];
        $stat = $this->stat;
        $range = $stat->range();
        $res['date_range'] = [ 'begin' => $range[0], 'end' => $range[1] ];
        $res['summary'] = $stat->summary();
        $res['sources'] = $stat->ips();
        $res['organizations'] = $stat->organizations();
        return $res;
    }

    /**
     * Returns the subject string. It is used in email messages.
     *
     * @return string
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * Returns the report as an array of text strings
     *
     * @return array
     */
    public function text(): array
    {
        $res = [];
        $res[] = '# Domain: ' . $this->domain->fqdn();

        $stat = $this->stat;

        $range = $stat->range();
        $res[] = ' Range: ' . $range[0]->format('M d') . ' - ' . $range[1]->format('M d');
        $res[] = '';

        $summ = $stat->summary();
        $total = $summ['emails']['total'];
        $aligned = $summ['emails']['dkim_spf_aligned'] +
            $summ['emails']['dkim_aligned'] +
            $summ['emails']['spf_aligned'];
        $n_aligned = $total - $aligned;
        $res[] = '## Summary';
        $res[] = sprintf(' Total: %d', $total);
        if ($total > 0) {
            $res[] = sprintf(' DKIM or SPF aligned: %s', self::num2percent($aligned, $total));
            $res[] = sprintf(' Not aligned: %s', self::num2percent($n_aligned, $total));
        } else {
            $res[] = sprintf(' DKIM or SPF aligned: %d', $aligned);
            $res[] = sprintf(' Not aligned: %d', $n_aligned);
        }
        $res[] = sprintf(' Organizations: %d', $summ['organizations']);
        $res[] = '';

        if (count($stat->ips()) > 0) {
            $res[] = '## Sources';
            $res[] = sprintf(
                ' %-25s %13s %13s %13s',
                '',
                'Total',
                'SPF aligned',
                'DKIM aligned'
            );
            foreach ($stat->ips() as &$it) {
                $total = $it['emails'];
                $spf_a = $it['spf_aligned'];
                $dkim_a = $it['dkim_aligned'];
                $spf_str = self::num2percent($spf_a, $total);
                $dkim_str = self::num2percent($dkim_a, $total);
                $res[] = sprintf(
                    ' %-25s %13d %13s %13s',
                    $it['ip'],
                    $total,
                    $spf_str,
                    $dkim_str
                );
            }
            unset($it);
            $res[] = '';
        }

        if (count($stat->organizations()) > 0) {
            $res[] = '## Organizations';
            $res[] = sprintf(' %-15s %8s %8s', '', 'emails', 'reports');
            foreach ($stat->organizations() as &$org) {
                $res[] = sprintf(
                    ' %-15s %8d %8d',
                    $org['name'],
                    $org['emails'],
                    $org['reports']
                );
            }
            unset($org);
            $res[] = '';
        }

        return $res;
    }

    /**
     * Returns the percentage with the original number. If $per is 0 then '0' is returned.
     *
     * @param int $per  Value
     * @param int $cent Divisor for percentage calculation
     *
     * @return string
     */
    private static function num2percent(int $per, int $cent): string
    {
        if (!$per) {
            return '0';
        }
        return sprintf('%.0f%%(%d)', $per / $cent * 100, $per);
    }
}
