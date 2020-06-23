<?php // coding: utf-8
/**
 * Library fuer Datenaustausch EBICS
 * @package library.ebics
 * @author Hans-Stefan Mueller
 * @copyright Copyright (C) 2019 Hans-Stefan Mueller
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License, version 3 or later
 * @version $Id: sepa.php 3606 2019-11-03 15:05:47Z root $
 * @link https://energie-m.de energie-m.de
 */
//namespace EBICS;

defined( '_JEXEC' ) or die( 'Restricted access' );



/**
 * Library Klasse
 * EBICS - Electronic Banking Internet Communication Standard - http://www.ebics.de
 * SEPA-Datenformate nach SIO 20022 - Spezifikation der Deutschen Kreditwirtschaft
 * Version 2.7 vom 25.3.2013 (gueltig ab 4.11.2013)
 * @package library.ebics
 */
class EBICS_Sepa {

	/* Timestamp (Linux-Date) */
	var $iTimeStamp;
	/* Eindeutige ID der SEPA-Nachricht */
	var $sMessageId;
	/* Payment Information <PmtInf> */
	var $oPaymentInformation;
	/* Transaction Information <DrctDbtTxInf> */
	var $aTransactionInformation;
	/* LocalInstrument <LclInstrm>  Werte: CORE, COR1, B2B */
	var $PaymentLclInstrm;
	/* Summe aller Transaktionen */
	var $TransactionCtrlSum;
	/* XML-Dokument */
	var $xml;
	/* Zeichensatz fuer SEPA-Nachrichten */
	var $validChars = array(
		0x20, 0x27, 0x28, 0x29, 0x2b, 0x2c, 0x2d, 0x2e, 0x2f, 0x3a, 0x3f,		// Sonderzeichen
		0x30, 0x31, 0x32, 0x33, 0x34, 0x35, 0x36, 0x37, 0x38, 0x39,				// Ziffern
		0x41, 0x42, 0x43, 0x44, 0x45, 0x46, 0x47, 0x48, 0x49, 0x4a, 0x4b, 0x4c, 0x4d, 0x4e, 0x4f, 
		0x50, 0x51, 0x52, 0x53, 0x54, 0x55, 0x56, 0x57, 0x58, 0x59, 0x5a,	// Grossbuchstaben
		0x61, 0x62, 0x63, 0x64, 0x65, 0x66, 0x67, 0x68, 0x69, 0x6a, 0x6b, 0x6c, 0x6d, 0x6e, 0x6f, 
		0x70, 0x71, 0x72, 0x73, 0x74, 0x75, 0x76, 0x77, 0x78, 0x79, 0x7a);	// Kleinbuchstaben
	/* Fehlerliste */
	var $errors = array();


	/**
	 * Konstruktor-Funktion
	 */
	function EBICS_Sepa( $InitiatorName ) {
		$this->iTimeStamp = time();
		$this->sMessageId = 'ID-' . $this->iTimeStamp;	// Eindeutige ID
		$this->sInitiatorName = $this->testString( $InitiatorName, 70 );
        $this->oPaymentInformation = new stdClass();
		$this->aTransactionInformation = array();
		$this->TransactionCtrlSum = 0.0;
	}


	/**
	 * Ausfuehrungsdatum setzen
	 * fuer CORE: erst- und einmalige LS 5 Tage vorher, wiederkehrende und letzmalige 2 Tage vorher (Mo-Fr), bei Einreichung bis 7.00 Uhr
	 * @param int Linux-Timestamp
	 * @return void
	 */
	function setReqdColltnDt( $time=null ) {
		$max_time = strtotime( "+8 day" );
		if ( !empty( $time ) && $time > $this->timestamp && $time <= $max_time ) {
			$this->execution_date = date( 'dmY', $time );
		}
	}


	/**
	 * Teste Zeichenkette auf gueltige Zeichen und Umwandlung von Sonderzeichen
	 * @param  string  $subject Zeichenkette fuer den Test
	 * @return string
	 */
	function testString ( $sString, $nMax=NULL ) {
		$search  = array( 'Ä', 'Ö', 'Ü', 'ß', 'ä', 'ö', 'ü', '&', '*', '$', '%' );
		$replace = array( 'Ae', 'Oe', 'Ue', 'ss', 'ae', 'oe', 'ue', '+', '.', '.', '.' ); 
		$sResult = str_replace( $search, $replace, $sString );				// Umlaute/Sonderzeichen Konvertieren
		if ( !is_null( $nMax )) $sResult = substr( $sResult, 0, $nMax );	// Max. Laenge
		for ( $i = 0; $i < strlen($sResult); $i++ ) {									// Unzulaessige Zeichen ersetzten
			if (! in_array( ord( substr( $sResult, $i, 1)), $this->validChars )) {
				$sResult[$i] = " ";
			}
		}
		return $sResult;
	}


	/**
	 * setPaymentInformation -- Informationen zum Glaeubiger und zur Typ der Zahlung
	 * @param array $aCdtr Kontodaten des Glaeubigers (Kreditors)
	 * @param string $ReqdColltnDt Faelligkeitsdatum JJJJ-MM-TT
	 * @param string $SeqTp Wiederholung FRST=Erst LS|RCUR=Folge LS|OOFF=Einmal LS|FNAL=letze LS
	 * @param string $LclInstrm Art der Lastschrift CORE=Basis LS | COR1=SEPA-Basislastschrift mit D-1-Vereinbarung | B2B=Firmen-LS
	 * @return boolean
	 */
	function setPaymentInformation( $aCdtr, $ReqdColltnDt=NULL, $SeqTp='OOFF', $LclInstrm='CORE' ) {
		$bError = false;
		$this->oPaymentInformation->LclInstrm = $LclInstrm;
		$this->oPaymentInformation->SeqTp = $SeqTp;
//		$this->oPaymentInformation->CtgyPurp = '';
		$this->oPaymentInformation->ReqdColltnDt = $ReqdColltnDt;
		$this->oPaymentInformation->CdtrNm = $this->testString( $aCdtr['name'], 70);
		$this->oPaymentInformation->CdtrAcct = $aCdtr['iban'];
		$this->oPaymentInformation->CdtrAgt = strtoupper( $aCdtr['bic'] );
		$this->oPaymentInformation->CdtrSchmeId = $aCdtr['glaeubiger_id'];

		if ( !strlen( $this->oPaymentInformation->CdtrSchmeId ) > 7 ) { $this->errors[] = '(PI) Gläubiger-Identifikationsnummer (CI) hat zu wenig Zeichen: ' . $this->oPaymentInformation->CdtrSchmeId; $bError = true; }
		if ( !strlen( $this->oPaymentInformation->CdtrNm ) > 0 ) { $this->errors[] = '(PI) Name des Gläubigers (Kreditors) ist leer'; $bError = true; }
		if ( strlen( $this->oPaymentInformation->CdtrAgt ) < 8 && strlen($this->oPaymentInformation->CdtrAgt) > 11 ) { $this->errors[] = '(PI) BIC des Gläubigers (Kreditors) hat eine unzulässige Länge: ' . $this->oPaymentInformation->CdtrAgt; $bError = true; }
		if ( strlen( $this->oPaymentInformation->CdtrAcct ) != 22 ) { $this->errors[] = '(PI) IBAN des Gläubigers (Kreditors) muss 22-stellig sein: '.  $this->oPaymentInformation->CdtrAcct; $bError = true; }
		// Pruefung IBAN Pruefziffer ???
		if ( !is_numeric( substr( $this->oPaymentInformation->CdtrAcct, 2 ))) { $this->errors[] = '(PI) IBAN des Gläubigers (Kreditors) ist ab der 3. Stelle keine Zahl: '. $this->oPaymentInformation->CdtrAcct; $bError = true;}
			return $bError;
	}


	/**
	 * addTransaction -- Transaktion (Zahlungs- und Schuldnerdaten) hinzufuegen
	 * @param array $aDbtr Kontodaten des Schuldners (Debitor)
	 * @param string $aMndt Daten des LS-Mandats
	 * @param string $dInstdAmt Zahlbetrag
	 * @param string $sRmtInf Verwendungszweck (<=140 Zeichen) bzw. 4 x 35 Zeichen VR-Networld
	 * @return boolean
	 */
	function addTransaction($aDbtr, $aMndt, $dInstdAmt, $sRmtInf ) {
		$bError = false;
		$oTransaction = new stdClass();
		$oTransaction->InstdAmt = $dInstdAmt;		// Zahlbetrag
		$oTransaction->MndtId = $aMndt['id'];			// Mandats-ID
		$oTransaction->DtOfSgntr = $aMndt['datum'];	// Ausstellungsdatum des Mandats
		$oTransaction->OrgnlMndtId = isset( $aMndt['id_alt'] ) ? $aMndt['id_alt'] : NULL;	// Alte Mandats-ID
		$oTransaction->DbtrAgt = strtoupper( $aDbtr['bic'] );		// BIC des Schuldners (Debitors)
		$oTransaction->DbtrNm = $this->testString( $aDbtr['name'], 70 );		// Name des Schuldners (Debitors)
		$oTransaction->DbtrAcct = $aDbtr['iban'];		// IBAN des Schuldners (Debitors)
//		$oTransaction->Purp = '';
		$oTransaction->RmtInf = $this->testString( $sRmtInf, 140 );		// Verwendungszweck max. 140 Zeichen

		if ( empty( $oTransaction->MndtId )) { $this->errors[] = '(TI) Mandats-ID des Schuldners (Debitors) ist leer'; $bError = true; }
		if ( empty( $oTransaction->InstdAmt) || $oTransaction->InstdAmt < 0 ) { $this->errors[] = '(TI) Der Zahlbetrag hat einen unzulaessigen Wert: ' . number_format( $oTransaction->InstdAmt, 2, '.', '' ) . ' EUR'; $bError = true; }
        if ( strlen( $oTransaction->DbtrAgt ) < 8 && strlen( $oTransaction->DbtrAgt ) > 11 ) { $this->errors[] = '(TI) BIC des Schuldners (Debitors) hat eine unzulässige Länge: ' . $oTransaction->DbtrAgt; $bError = true; }
		if ( !strlen( $oTransaction->DbtrNm ) > 0 ) { $this->errors[] = '(TI) Name des Schuldners (Debitors) ist leer'; $bError = true; }
		if ( strlen( $oTransaction->DbtrAcct ) != 22 ) { $this->errors[] = '(TI) IBAN des Schuldners (Debitors) muss 22-stellig sein: '.  $oTransaction->DbtrAcct; $bError = true; }
		// Pruefung IBAN Pruefziffer ???
		if ( !is_numeric( substr( $oTransaction->DbtrAcct, 2 ))) { $this->errors[] = '(TI) IBAN des Schuldners (Debitors) ist ab der 3. Stelle keine Zahl: '. $oTransaction->DbtrAcct; $bError = true;}

		if ( !$bError ) {
			$this->aTransactionInformation[] = $oTransaction;
			$this->TransactionCtrlSum += $oTransaction->InstdAmt;
		}
		return $bError;
	}


	/**
	 * getDirectDebitInitiation - SEPA-Lastschrifteinzugsauftrag / Direct Debit Initiation (SDD - pain.008.001.02) Seite 41ff.
	 * Direct Debit Initiation Auftragsart CDD (SEPA-Basislastschrift) und CDB (SEPA-Firmenlastschrift)
	 * XML-Nachricht (Text) generieren
	 * @return string XML-Nachricht (UTF-8)
	 */
	function getDirectDebitInitiation() {
		$this->xml = new XMLWriter();
		$this->xml->openMemory();
		$this->xml->startDocument( '1.0', 'UTF-8' );

		// Version 3.3 (vom 11.04.2019 - gueltig ab 17.11.2019)
		$this->xml->startElementNS(NULL, 'Document', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02' );
		$this->xml->writeAttributeNS( 'xmlns', 'xsi', NULL, 'http://www.w3.org/2001/XMLSchema-instance' );
		$this->xml->writeAttributeNS( 'xsi', 'schemaLocation', NULL, 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02 pain.008.001.02.xsd' );
		$this->xml->startElement( 'CstmrDrctDbtInitn' );	// Kunden-SEPA-Lastschrifteinzugsauftrag CDD

		// GroupHeader SDD
		$this->xml->startElement( 'GrpHdr' );	// Kenndaten, die fuer alle Transaktionen innerhalb der SEPA-Nachricht gelten
		$this->xml->writeElement( 'MsgId', $this->sMessageId );	// MessageIdentification: Eindeutige Nachrichten ID
		$this->xml->writeElement( 'CreDtTm', date( 'Y-m-d\TH:i:s' ) . '.000Z' );   // 2010-11-21T09:30:47.000Z
		$this->xml->writeElement( 'NbOfTxs', count( $this->aTransactionInformation ) );
		$this->xml->writeElement( 'CtrlSum', number_format( $this->TransactionCtrlSum, 2, '.', '' ) );
		$this->xml->startElement( 'InitgPty' );	// InitiatingParty - auch abweichend vom Creditor
		$this->xml->writeElement( 'Nm', $this->sInitiatorName );	// Name, max. 70 Zeichen
		$this->xml->endElement(); // InitgPty
		$this->xml->endElement(); // GrpHdr

		// PaymentInformation SDD
		$this->xml->startElement( 'PmtInf' );	// Payment Information -> Satz von Informationen fuer alle Transaktionen

		$this->xml->writeElement( 'PmtInfId', $this->sMessageId . '-PI001' );	// PaymentInformationIdentification: Eindeutige Referenz des folgenden Sammlers
		$this->xml->writeElement( 'PmtMtd', 'DD' );	// PaymentMethod (default: DD)
		$this->xml->writeElement( 'BtchBookg', 'true' );	// BatchBooking: nur bei Vereinbarung als Einzelbuchung (default: true = Sammelbuchung)
		$this->xml->writeElement( 'NbOfTxs', count( $this->aTransactionInformation ) );	// Anzahl der Transaktionen innerhalb eines PaymentInformation-Blocks
		$this->xml->writeElement( 'CtrlSum', number_format( $this->TransactionCtrlSum, 2, '.', '' ) );	// ControlSum: Summe der Beträge aller Transaktionen

		// Diese Gruppe (PmtTpInf) ist entweder hier oder bei den einzelnen Transaktionen zu verwenden!!
		$this->xml->startElement( 'PmtTpInf' );	// PaymentTypeInformation
		$this->xml->startElement( 'SvcLvl' );	// ServiceLevel
		$this->xml->writeElement( 'Cd', 'SEPA' );	// Code der vereinbarten Service-Leistung: SEPA
		$this->xml->endElement(); // SvcLvl
		$this->xml->startElement( 'LclInstrm' );	// LocalInstrument
		$this->xml->writeElement( 'Cd', $this->oPaymentInformation->LclInstrm );	// Art der Lastschrift -> Basis-Lastschrift: CORE, Firmenlastschrift: B2B
		$this->xml->endElement(); // LclInstrm
		$this->xml->writeElement( 'SeqTp', $this->oPaymentInformation->SeqTp );	// SequenceType - Art der Wiederholung (siehe oben)
		//	<CtgyPurp><Cd> $this->oPaymentInformation->CtgyPurp </Cd></CtgyPurp>		// CategoryPurpose: 4-stellige Codes fuer Verwendungsschluessel (ISO 20022) -- optional nicht implementiert
		$this->xml->endElement(); // PmtTpInf

		$this->xml->writeElement( 'ReqdColltnDt', $this->oPaymentInformation->ReqdColltnDt );	// RequestedCollectionDate - Faelligkeitsdatum der LS

		$this->xml->startElement( 'Cdtr' );	// Creditor: Name Glaeubiger
		$this->xml->writeElement( 'Nm', $this->oPaymentInformation->CdtrNm );
		$this->xml->endElement(); // Cdtr
		$this->xml->startElement( 'CdtrAcct' );	// CreditorAccount
		$this->xml->startElement( 'Id' );	// Identification
		$this->xml->writeElement( 'IBAN', $this->oPaymentInformation->CdtrAcct );	// IBAN Glaeubiger
		$this->xml->endElement(); // Id
		$this->xml->endElement(); // CdtrAcct
		$this->xml->startElement( 'CdtrAgt' );	// CreditorAgent: Kreditinstitut des Gläubigers
		$this->xml->startElement( 'FinInstnId' );	// FinancialInstitutionIdentification
		$this->xml->writeElement( 'BIC', $this->oPaymentInformation->CdtrAgt );	// BIC Glaeubiger
		$this->xml->endElement(); // FinInstnId
		$this->xml->endElement(); // CdtrAgt
//		<UltmtCdtr>...</UltmtCdtr>	// UltimateCreditor: abweichender Zahlungsempfaenger --> nicht verwendet
		$this->xml->writeElement( 'ChrgBr', 'SLEV' );	// ChargeBearer - Entgeltverrechnung (default: SLEV)
		$this->xml->startElement( 'CdtrSchmeId' );	// CreditorScheme-Identification: Identifikation des Zahlungsempfängers
		$this->xml->startElement( 'Id' );	// Identification
		$this->xml->startElement( 'PrvtId' );	// PrivateIdentification
		$this->xml->startElement( 'Othr' );	// OtherIdentification
		$this->xml->writeElement( 'Id', $this->oPaymentInformation->CdtrSchmeId ); // Gläubiger ID
		$this->xml->startElement( 'SchmeNm' );	// SchemeName
		$this->xml->writeElement( 'Prtry', 'SEPA' );	// Proprietary
		$this->xml->endElement(); // SchmeNm
		$this->xml->endElement(); // Othr
		$this->xml->endElement(); // PrvtId
		$this->xml->endElement(); // Id
		$this->xml->endElement(); // CdtrSchmeId

		// Direct Debit Transaction Information --> Einzeltransaktionen
		for ($i = 0; $i <  count( $this->aTransactionInformation ); $i++ ) {
			// Einzeltransaktion mit Information zum Schuldner (Debitor)
			$this->xml->startElement( 'DrctDbtTxInf' );	// DirectDebitTransactionInformation: Einzeltransaktion
			$this->xml->startElement( 'PmtId' );	// PaymentIdentification: Eindeutige Kennzeichnung der Transaktion
			$this->xml->writeElement( 'EndToEndId', $this->sMessageId . '-PI001-TI' . sprintf( '%03d', $i+1 ) );	// EndToEndIdentification
			$this->xml->endElement(); // PmtId
			$this->xml->startElement( 'InstdAmt');	// Zahlbetrag
			$this->xml->writeAttribute( 'Ccy', 'EUR' );
			$this->xml->text( number_format( $this->aTransactionInformation[$i]->InstdAmt, 2, '.', '' ) );
			$this->xml->endElement(); // InstdAmt
//			$this->xml->writeElement( 'ChrgBr', 'SLEV' );	// ChargeBearer - Entgeltverrechnung --> nicht hier, sondern bei PaymentInformation verwenden!!

			// Direct Debit Transaction (DrctDbtTx) = Informationen zm Lastschrift-Mandat
			$this->xml->startElement( 'DrctDbtTx');	//
			$this->xml->startElement( 'MndtRltdInf');	// MandateRelatedInformation: Mandatsbezogene Informationen
			$this->xml->writeElement( 'MndtId', $this->aTransactionInformation[$i]->MndtId );	// Mandate-Identification: ID des Mandats
			$this->xml->writeElement( 'DtOfSgntr', $this->aTransactionInformation[$i]->DtOfSgntr );	// Ausstellungsdatum des Mandats YYYY-MM-DD
			// Aenderungen im Mandat --> Angaben zum bisherigen Mandat
			if ( ! is_null( $this->aTransactionInformation[$i]->OrgnlMndtId )) {
				$this->xml->writeElement( 'AmdmntInd', 'true' );	// AmendmentIndicator: Veraendertes Mandat
				$this->xml->startElement( 'AmdmntInfDtls');	// Details der Mandatsaenderung = Amendment Information Details
				$this->xml->writeElement( 'OrgnlMndtId', $this->aTransactionInformation[$i]->OrgnlMndtId );	// ID des bisherigen Mandats bei veraendertem Mandat
				// Informationen zum bisherigen Glaeubiger
				//	<OrgnlCdtrSchmeId>		// Aenderungen bei Glaeubiger (Kreditor) --> bisherige Glaeubigerdaten
				//	<Nm>...Name...</Nm>		// urspruenglicher Name des Glaeubigers (Kreditors)
				//	<Id><PrvtId><Othr><Id>...Glaeubiger-ID...</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id>
				//	</OrgnlCdtrSchmeId>
				// Informationen zur bisherigen Kontoverbindung
				//	<OrgnlDbtrAcct><Id><IBAN>...IBAN...</IBAN></Id></DbtrAcct>'		// Bisherige Kontonummer des Schuldners
				//	<OrgnlDbtrAgt><FinInstnId><Other><Id>...</Id></Other></FinInstnId></DbtrAgt>		// bisherige Bank des Schuldners
				$this->xml->endElement(); // AmdmntInfDtls
			}
//		$this->xml->writeElement( 'ElctrncSgntr', ... );	// nur fuer elektronische Signatur --> nicht implementiert
			$this->xml->endElement(); // MndtRltdInf
//			$this->xml->writeElement( 'CdtrSchemeId', ... );	// Glaeubiger-ID entfaellt, wenn diese bei PaymentInformationen angegeben wurde!
			$this->xml->endElement(); // DrctDbtTx

			//	<UltmtCdtr>...</UltmtCdtr>		// abweichender Zahlungsempfaenger --> nicht verwendet

			// Informationen zum Schuldner (Dbtr = Debitor)
			$this->xml->startElement( 'DbtrAgt' );	// DebtorAgent: Kreditinstitut des Schuldners
			$this->xml->startElement( 'FinInstnId' );	// FinancialInstitutionIdentification
			$this->xml->writeElement( 'BIC', $this->aTransactionInformation[$i]->DbtrAgt );	// BIC Schuldner
			$this->xml->endElement(); // FinInstnId
			$this->xml->endElement(); // DbtrAgt
			$this->xml->startElement( 'Dbtr' );	// Debtor: Schuldner
			$this->xml->writeElement( 'Nm', $this->aTransactionInformation[$i]->DbtrNm );	// Name des Schuldners max. 70 Zeichen
			$this->xml->endElement(); // Dbtr
			$this->xml->startElement( 'DbtrAcct' );	// DebtorAccount: Konto des Schuldners
			$this->xml->startElement( 'Id' );	// Identification
			$this->xml->writeElement( 'IBAN', $this->aTransactionInformation[$i]->DbtrAcct );	// IBAN des Schuldners
			$this->xml->endElement(); // Id
			$this->xml->endElement(); // DbtrAcct
			//	<UltmtDbtr><Nm>...</Nm></UltmtDbtr>		// Abweichender Schuldner (Debitor) --> nicht verwendet
			//	<Purp><Cd> $this->aTransactionInformation[$i]->Purp </Cd></Purp>	// Purpose: 11-stellige Codes fuer Verwendungsschluessel (ISO 20022) -- optional nicht implementiert
			$this->xml->startElement( 'RmtInf' );	// Remittance Information <RmtInf> = Verwendungszweckinformation 140 Zeichen
			$this->xml->writeElement( 'Ustrd', $this->aTransactionInformation[$i]->RmtInf );
			$this->xml->endElement(); // RmtInf
			$this->xml->endElement(); // DrctDbtTxInf
		}	// Einzeltransaktionen
		$this->xml->endElement(); // PmtInf

		$this->xml->endElement(); // CstmrDrctDbtInitn -> Kundenlastschrift
		$this->xml->endElement(); // Document
		$this->xml->endDocument();
		return $this->xml->outputMemory();
	}


	/**
	 * EBICS XML-Datei in Datei schreiben
	 * @param  string  $filename Filename.
	 * @return boolean
	 */
	function saveFile( $filename ) {
		$content = $this->getDirectDebitInitiation();
		$fpEbics = @fopen($filename, "w");
		if (!$fpEbics) {
			$result = false;
		} else {
			$result = @fwrite($fpEbics, $content);
			@fclose($fpEbics);
		}
		return $result;
	}

// Ende der Klasse
}

